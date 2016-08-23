# Azure Cloud Services Management
Manage Azure Cloud Services.
Features are:
* Reboot all instances of a cloud service.
* Re-image all instances of a cloud service.

## Requirement
* PHP 5.3 or above
* Composer

## Install
```
git clone git@github.com:zamec75/azure-cloud-services-management.git
cd azure-cloud-services-management
composer install
```

## How to use
### Reboot
```
bin/reboot.php <subscription-id> <certificate-filepath> <service-name>
```

### Re-image
```
bin/reimage.php <subscription-id> <certificate-filepath> <service-name>
```
