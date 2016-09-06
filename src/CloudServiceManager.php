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
     * @param string $serviceName
     * @param string $slot
     */
    public function reImage($serviceName, $slot = DeploymentSlot::PRODUCTION)
    {
        // Get deployment information.
        $options = new GetDeploymentOptions();
        $options->setSlot($slot);
        $deploy = $this->proxy->getDeployment($serviceName, $options);

        // Re-image each instance.
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
        $date = date('Y-m-d H:i:s');
        echo "Done at {$date}.\n";
    }

    /**
     * Reboot all instances of a cloud service.
     * @param string $serviceName
     * @param string $slot
     */
    public function reboot($serviceName, $slot = DeploymentSlot::PRODUCTION)
    {
        // Get deployment information.
        $options = new GetDeploymentOptions();
        $options->setSlot($slot);
        $deploy = $this->proxy->getDeployment($serviceName, $options);

        // Re-image each instance.
        foreach ($deploy->getDeployment()->getRoleInstanceList() as $instance) {
            /**
             * @var $instance RoleInstance
             */
            $date = date('Y-m-d H:i:s');
            echo "Start to re-image {$instance->getInstanceName()} at {$date}\n";
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
}
