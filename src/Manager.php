<?php

namespace Drupal\extension_configuration;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\InstallStorage;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Extension\InfoParserInterface;

/**
 * Extension configuration manager service.
 */
class Manager {

  const CONFIG_STATUS_DEFAULT = 1;
  const CONFIG_STATUS_MISSING_IN_EXTENSION = 2;
  const CONFIG_STATUS_MISSING_IN_ACTIVE_STORAGE = 3;
  const CONFIG_STATUS_OVERRIDDEN = 4;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The config manager.
   *
   * @var \Drupal\Core\Config\ConfigManagerInterface
   */
  protected $configManager;

  /**
   * The info parser to parse the theme.info.yml files.
   *
   * @var \Drupal\Core\Extension\InfoParserInterface
   */
  protected $infoParser;

  /**
   * Constructs a Manager object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Config\ConfigManagerInterface $config_manager
   *   The config manager.
   * @param \Drupal\Core\Extension\InfoParserInterface $info_parser
   *   The info parser to parse the theme.info.yml files.
   */
  public function __construct(ConfigFactoryInterface $config_factory, ConfigManagerInterface $config_manager, InfoParserInterface $info_parser) {
    $this->configFactory = $config_factory;
    $this->configManager = $config_manager;
    $this->infoParser = $info_parser;
  }

  /**
   * Exports extension configuration from active storage.
   */
  public function export($extension) {

    $type = $this->getExtensionType($extension);
    $install_storage = new InstallStorage();
    $info_config = $this->getInfoConfig($type, $extension);

    $result['messages'] = [];
    foreach ($this->getConfigTypes() as $config_type => $directory) {
      $config_path = DRUPAL_ROOT . '/' . drupal_get_path($type, $extension) . '/' . $directory;
      foreach ($info_config[$config_type] as $config_name) {

        $file_path = $config_path . '/' . $config_name . '.yml';
        file_prepare_directory(dirname($file_path), FILE_CREATE_DIRECTORY);
        $config = $this->configFactory->get($config_name);

        if ($config->isNew()) {
          $result['messages'][] = [
            t('Config @config is missing in active storage.', ['@config' => $config_name]),
            'warning',
          ];
          continue;
        }

        $data = $config->get();
        unset($data['_core'], $data['uuid']);

        file_put_contents($file_path, $install_storage->encode($data));
        $result['messages'][] = [
          t('Exported @config.', ['@config' => $config_name]),
          'success',
        ];

      }
    }

    return $result;
  }

  /**
   * Imports extension configuration to active storage.
   */
  public function import($extension) {

    $type = $this->getExtensionType($extension);
    $install_storage = new InstallStorage();
    $info_config = $this->getInfoConfig($type, $extension);

    $result['messages'] = [];
    foreach ($this->getConfigTypes() as $config_type => $directory) {
      $config_path = DRUPAL_ROOT . '/' . drupal_get_path($type, $extension) . '/' . $directory;
      foreach ($info_config[$config_type] as $config_name) {

        $file_path = $config_path . '/' . $config_name . '.yml';
        if (!is_readable($file_path)) {
          $result['messages'][] = [
            t('Could not read file @file', ['@file' => $file_path]),
            'error',
          ];
          continue;
        }

        $content = file_get_contents($file_path);
        $data = $install_storage->decode($content);

        $entity_type_id = $this->configManager->getEntityTypeIdByName($config_name);
        if ($entity_type_id) {
          /** @var \Drupal\Core\Config\Entity\ConfigEntityStorageInterface $entity_storage */
          $entity_storage = $this->configManager->getEntityManager()->getStorage($entity_type_id);

          /** @var \Drupal\Core\Config\Entity\ConfigEntityType $entity_type */
          $entity_type = $entity_storage->getEntityType();

          $entity_id = $entity_storage::getIDFromConfigName($config_name, $entity_type->getConfigPrefix());

          $data[$entity_type->getKey('id')] = $entity_id;

          /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface $entity */
          $entity = $entity_storage->create($data);

          if ($existing_entity = $entity_storage->load($entity_id)) {
            $entity
              ->set('uuid', $existing_entity->uuid())
              ->enforceIsNew(FALSE);
          }
          $entity_storage->save($entity);
        }
        else {
          $this->configFactory->getEditable($config_name)->setData($data)->save();
        }

        $result['messages'][] = [
          t('Imported @config', ['@config' => $config_name]),
          'success',
        ];
      }
    }

    return $result;
  }

  /**
   * Deletes extension configuration from active storage.
   */
  public function delete($extension) {

    $type = $this->getExtensionType($extension);
    $install_storage = new InstallStorage();
    $info_config = $this->getInfoConfig($type, $extension);

    $result['messages'] = [];
    foreach ($this->getConfigTypes() as $config_type => $directory) {
      foreach ($info_config[$config_type] as $config_name) {

        $config = $this->configFactory->getEditable($config_name);
        if ($config->isNew()) {
          $result['messages'][] = [
            t('Configuration @config was not found.', ['@config' => $config_name]),
            'warning',
          ];
        }
        else {
          $config->delete();
          $result['messages'][] = [
            t('Deleted @config.', ['@config' => $config_name]),
            'success',
          ];
        }

      }
    }

    return $result;
  }

  /**
   * Finds untracked configuration files.
   */
  public function findUntrackedFiles($extension) {

    $type = $this->getExtensionType($extension);
    $install_storage = new InstallStorage();
    $info_config = $this->getInfoConfig($type, $extension);

    $result['files'] = [];
    foreach ($this->getConfigTypes() as $config_type => $directory) {
      $config_path = DRUPAL_ROOT . '/' . drupal_get_path($type, $extension) . '/' . $directory;
      $files = file_scan_directory($config_path, '/.*\.yml$/');
      foreach ($files as $file) {
        if (!in_array($file->name, $info_config[$config_type])) {
          $result['files'][] = $file;
        }
      }
    }

    return $result;
  }

  /**
   * Displays configuration status.
   */
  public function status($extension) {

    $type = $this->getExtensionType($extension);
    $install_storage = new InstallStorage();
    $info_config = $this->getInfoConfig($type, $extension);

    $enabled_extensions = $this->getEnabledExtensions();

    $all_config = $this->configFactory->listAll();

    $result['messages'] = [];
    $result['rows'] = [];
    foreach ($this->getConfigTypes() as $config_type => $directory) {
      $config_path = DRUPAL_ROOT . '/' . drupal_get_path($type, $extension) . '/' . $directory;
      foreach ($info_config[$config_type] as $config_name) {
        $file_path = $config_path . '/' . $config_name . '.yml';
        if (!is_readable($file_path)) {
          $result['rows'][] = [
            $config_name,
            $config_type,
            self::CONFIG_STATUS_MISSING_IN_EXTENSION,
          ];
          continue;
        }
        $content = file_get_contents($file_path);
        $stored_data = $install_storage->decode($content);

        $missing_dependencies = $this->getMissingDependencies($config_name, $stored_data, $enabled_extensions, $all_config);
        foreach ($missing_dependencies as $dependency) {
          $message = t(
            'Configuration @config has missing dependency @dependency.',
            ['@config' => $config_name, '@dependency' => $dependency]
          );
          $result['messages'][] = [$message, 'error'];
        }

        $active_config = $this->configFactory->getEditable($config_name);
        if ($active_config->isNew()) {
          $result['rows'][] = [
            $config_name,
            $config_type,
            self::CONFIG_STATUS_MISSING_IN_ACTIVE_STORAGE,
          ];
          continue;
        }
        $active_data = $active_config->get();
        unset($active_data['uuid']);

        $state = $active_data === $stored_data
          ? self::CONFIG_STATUS_DEFAULT
          : self::CONFIG_STATUS_OVERRIDDEN;

        $result['rows'][] = [
          $config_name,
          $config_type,
          $state,
        ];
      }
    }

    return $result;
  }

  /**
   * Determines the type of extension we are dealing with.
   */
  protected function getExtensionType($extension) {
    if (\Drupal::moduleHandler()->moduleExists($extension)) {
      return 'module';
    }
    elseif (\Drupal::service('theme_handler')->themeExists($extension)) {
      return 'theme';
    }
    elseif (\Drupal::installProfile() == $extension) {
      return 'profile';
    }
    else {
      throw new \InvalidArgumentException(sprintf('Extension %s is not enabled.', $extension));
    }
  }

  /**
   * Returns supported configuration types.
   */
  protected function getConfigTypes() {
    return [
      'install' => InstallStorage::CONFIG_INSTALL_DIRECTORY,
      'optional' => InstallStorage::CONFIG_OPTIONAL_DIRECTORY,
    ];
  }

  /**
   * Returns configuration items from info file.
   */
  protected function getInfoConfig($type, $extension) {
    $filename = drupal_get_path($type, $extension) . '/' . $extension . '.info.yml';
    $info = $this->infoParser->parse($filename);
    $info_config = [];
    foreach ($this->getConfigTypes() as $config_type => $directory) {
      $info_config[$config_type] = is_array($info['config'][$config_type]) ? $info['config'][$config_type] : [];
    }
    return $info_config;
  }

  /**
   * Returns an array of missing dependencies for a config object.
   *
   * @param string $config_name
   *   The name of the configuration object that is being validated.
   * @param array $data
   *   Configuration data.
   * @param array $enabled_extensions
   *   A list of all the currently enabled modules and themes.
   * @param array $all_config
   *   A list of all the active configuration names.
   *
   * @return array
   *   A list of missing config dependencies.
   */
  public function getMissingDependencies($config_name, array $data, array $enabled_extensions, array $all_config) {
    $missing = [];
    if (isset($data['dependencies'])) {
      list($provider) = explode('.', $config_name, 2);
      $all_dependencies = $data['dependencies'];

      // Ensure enforced dependencies are included.
      if (isset($all_dependencies['enforced'])) {
        $all_dependencies = array_merge($all_dependencies, $data['dependencies']['enforced']);
        unset($all_dependencies['enforced']);
      }
      // Ensure the configuration entity type provider is in the list of
      // dependencies.
      if (!isset($all_dependencies['module']) || !in_array($provider, $all_dependencies['module'])) {
        $all_dependencies['module'][] = $provider;
      }

      foreach ($all_dependencies as $type => $dependencies) {
        $list_to_check = [];
        switch ($type) {
          case 'module':
          case 'theme':
            $list_to_check = $enabled_extensions;
            break;

          case 'config':
            $list_to_check = $all_config;
            break;
        }
        if (!empty($list_to_check)) {
          $missing = array_merge($missing, array_diff($dependencies, $list_to_check));
        }
      }
    }

    return $missing;
  }

  /**
   * Gets the list of enabled extensions including both modules and themes.
   *
   * @return array
   *   A list of enabled extensions which includes both modules and themes.
   */
  public function getEnabledExtensions() {
    // Read enabled extensions directly from configuration to avoid circular
    // dependencies on ModuleHandler and ThemeHandler.
    $extension_config = $this->configFactory->get('core.extension');
    $enabled_extensions = (array) $extension_config->get('module');
    $enabled_extensions += (array) $extension_config->get('theme');
    // Core can provide configuration.
    $enabled_extensions['core'] = 'core';
    return array_keys($enabled_extensions);
  }

}
