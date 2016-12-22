<?php

namespace CloudServicesManagement;

use WindowsAzure\Common\ServicesBuilder;
use WindowsAzure\ServiceManagement\Models\DeploymentSlot;
use WindowsAzure\ServiceManagement\Models\GetDeploymentOptions;
use WindowsAzure\ServiceManagement\Models\RoleInstance;
use WindowsAzure\ServiceManagement\ServiceManagementRestProxy;

class CloudServiceManager
{
    /**
     * @var $proxy ServiceManagementRestProxy
     */
    private $proxy;

    /**
     * CloudServiceManager constructor.
     * @param string $subscriptionId
     * @param string $certificateFile
     */
    public function __construct($subscriptionId, $certificateFile)
    {
        if (empty($subscriptionId)) {
            throw new \InvalidArgumentException('$subscriptionId must be a subscription ID.');
        }
        if (empty($certificateFile) || !is_readable($certificateFile)) {
            throw new \InvalidArgumentException('$certificateFile must be a file path.');
        }

        $this->proxy = ServicesBuilder::getInstance()->createServiceManagementService(
            "SubscriptionID=$subscriptionId;CertificatePath=$certificateFile"
        );
    }

    /**
     * Re-image all instances of a cloud service.
     * @param string $serviceName Cloud Service name.
     * @param string $slot Deployment slot.
     * @param bool $sync if true, re-image one by one. if not, simultaneously.
     */
    public function reImage($serviceName, $slot = DeploymentSlot::PRODUCTION, $sync = true)
    {
        // Get deployment information.
        $options = new GetDeploymentOptions();
        $options->setSlot($slot);
        $deploy = $this->proxy->getDeployment($serviceName, $options);

        // Re-image each instance.
        if ($sync) {
            foreach ($deploy->getDeployment()->getRoleInstanceList() as $instance) {
                /**
                 * @var $instance RoleInstance
                 */
                $date = date('Y-m-d H:i:s');
                echo "Start to re-image {$instance->getInstanceName()} at {$date}\n";
                $this->proxy->reimageRoleInstance($serviceName, $instance->getInstanceName(), $options);

                // Wait for start re-imaging.
                $this->waitFor($this->proxy, $serviceName, $options, $instance->getInstanceName(), false);
                // Wait for re-imaging completes.
                $this->waitFor($this->proxy, $serviceName, $options, $instance->getInstanceName(), true);
            }
        } else {
            $date = date('Y-m-d H:i:s');
            echo "Start to re-image at {$date}\n";
            foreach ($deploy->getDeployment()->getRoleInstanceList() as $instance) {
                /**
                 * @var $instance RoleInstance
                 */
                $this->proxy->reimageRoleInstance($serviceName, $instance->getInstanceName(), $options);
            }

            echo "Waiting for start re-imaging.\n";
            $this->waitForAny($this->proxy, $serviceName, $options, false);

            echo "Waiting for complete re-imaging.\n";
            $this->waitForAll($this->proxy, $serviceName, $options, true);
        }
        $date = date('Y-m-d H:i:s');
        echo "Done at {$date}.\n";
    }

    /**
     * Reboot one or all instances of a cloud service.
     * @param string $serviceName
     * @param string $slot
     * @param string $targetInstance Name of target instance.
     */
    public function reboot($serviceName, $slot = DeploymentSlot::PRODUCTION, $targetRole = '')
    {
        // Get deployment information.
        $options = new GetDeploymentOptions();
        $options->setSlot($slot);
        $deploy = $this->proxy->getDeployment($serviceName, $options);

        // Reboot instances.
        foreach ($deploy->getDeployment()->getRoleInstanceList() as $instance) {
            // Skip if not target.
            if (!empty($targetRole) && $instance->getInstanceName() !== $targetRole) {
                continue;
            }

            /**
             * @var $instance RoleInstance
             */
            $date = date('Y-m-d H:i:s');
            echo "Start to reboot {$instance->getInstanceName()} at {$date}\n";
            $this->proxy->rebootRoleInstance($serviceName, $instance->getInstanceName(), $options);

            // Wait for start reboot.
            $this->waitFor($this->proxy, $serviceName, $options, $instance->getInstanceName(), false);
            // Wait for reboot completes.
            $this->waitFor($this->proxy, $serviceName, $options, $instance->getInstanceName(), true);
        }
        $date = date('Y-m-d H:i:s');
        echo "Done at {$date}.\n";
    }

    /**
     * Wait for target instance get ready or not.
     * @param ServiceManagementRestProxy $proxy
     * @param string $serviceName Name of cloud service.
     * @param GetDeploymentOptions $getDeploymentOptions
     * @param string $instanceName Instance name.
     * @param bool $forReady if true, wait for target instance get ready, else not ready.
     * @throws TimeoutException
     * @throws UnknownException
     */
    private function waitFor($proxy, $serviceName, $getDeploymentOptions,
                             $instanceName, $forReady)
    {
        $try = 0;
        do {
            sleep(30);
            if ($try++ >= 40) { // 20 minutes timeout.
                throw new TimeoutException('Timeout.');
            }

            $instanceList = $proxy->getDeployment($serviceName, $getDeploymentOptions)->getDeployment()->getRoleInstanceList();
            $instancesStatus = array_filter($instanceList, function ($x) use ($instanceName) {
                /**
                 * @var $x RoleInstance
                 */
                return $x->getInstanceName() === $instanceName;
            });
            if (empty($instancesStatus[0])) {
                throw new UnknownException('Instance is not found.');
            }
            /**
             * @var $instanceStatus RoleInstance
             */
            $instanceStatus = $instancesStatus[0];
        } while (($instanceStatus->getInstanceStatus() === 'ReadyRole') !== $forReady);
    }

    /**
     * Wait for any instance get ready or not.
     * @param ServiceManagementRestProxy $proxy
     * @param string $serviceName Name of cloud service.
     * @param GetDeploymentOptions $getDeploymentOptions
     * @param bool $forReady if true, wait for any instance get ready, else not ready.
     * @throws TimeoutException
     * @throws UnknownException
     */
    private function waitForAny($proxy, $serviceName, $getDeploymentOptions, $forReady)
    {
        $try = 0;
        do {
            sleep(30);
            if ($try++ >= 40) { // 20 minutes timeout.
                throw new TimeoutException('Timeout.');
            }

            $instanceList = $proxy->getDeployment($serviceName, $getDeploymentOptions)->getDeployment()->getRoleInstanceList();
            if (empty($instanceList)) {
                throw new UnknownException('Instance is not found.');
            }

            foreach ($instanceList as $instance) {
                /**
                 * @var $instance RoleInstance
                 */
                if (($instance->getInstanceStatus() === 'ReadyRole') === $forReady) {
                    break 2;
                }
            }
        } while (true);
    }

    /**
     * Wait for all instance get ready or not.
     * @param ServiceManagementRestProxy $proxy
     * @param string $serviceName Name of cloud service.
     * @param GetDeploymentOptions $getDeploymentOptions
     * @param bool $forReady if true, wait for all instances get ready, else not ready.
     * @throws TimeoutException
     * @throws UnknownException
     */
    private function waitForAll($proxy, $serviceName, $getDeploymentOptions, $forReady)
    {
        $try = 0;
        do {
            sleep(30);
            if ($try++ >= 40) { // 20 minutes timeout.
                throw new TimeoutException('Timeout.');
            }

            $instanceList = $proxy->getDeployment($serviceName, $getDeploymentOptions)->getDeployment()->getRoleInstanceList();
            if (empty($instanceList)) {
                throw new UnknownException('Instance is not found.');
            }

            $conditionsOfEach = array_map(function ($instance) use ($forReady) {
                /**
                 * @var $instance RoleInstance
                 */
                return ($instance->getInstanceStatus() === 'ReadyRole') === $forReady;
            }, $instanceList);
            $condition = array_reduce($conditionsOfEach, function ($x, $y) {
                return $x && $y;
            }, true);
        } while (!$condition);
    }
}
