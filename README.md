# Extension configuration Drupal module

The module offers a set of Drush commands for managing extension configuration.

## Usage
Tracked configuration items should be defined in _info.yml_ file under "config" section as follows:

```yml
name: Example
type: module
description: Example description.
version: 8.x-1.0-dev
core: 8.x
config:
  install:
    - example.settings
    - node.type.example
    - core.entity_view.display.node.example.default
  optional:
    - image.style.max_1024x1024
```

### Available Drush commands

Name | Alias | Description
----|-----|-----------
extension-configuration-export  | ec-export | Export configuration to extension config directory.
extension-configuration-import  | ec-import | Import configuration from extension config directory.
extension-configuration-delete | ec-delete | Delete configuration from active storage.
extension-configuration-clean | ec-clean | Remove untracked yml files from the config directory.
extension-configuration-status | ec-status | Display configuration status.

## Credits
Inspired by [Configuration development](https://www.drupal.org/project/config_devel) module.

## License
GNU General Public License, version 2.
