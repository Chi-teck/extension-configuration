<?php

/**
 * @file
 * Contains extension-configuration drush commands.
 */

/**
 * Implements hook_drush_command().
 */
function extension_configuration_drush_command() {

  $defaults = [
    'arguments' => [
      'extension' => 'An extension to process configuration.',
    ],
    'required-arguments' => TRUE,
  ];

  $items['extension-configuration-export'] = $defaults + [
    'description' => 'Export configuration to extension config directory.',
    'aliases' => ['ec-export'],
  ];

  $items['extension-configuration-import'] = $defaults + [
    'description' => 'Import configuration from extension config directory.',
    'aliases' => ['ec-import'],
  ];

  $items['extension-configuration-delete'] = $defaults + [
    'description' => 'Delete extension configuration from active storage.',
    'aliases' => ['ec-delete'],
  ];

  $items['extension-configuration-status'] = $defaults + [
    'description' => 'Display configuration status.',
    'aliases' => ['ec-status'],
  ];

  $items['extension-configuration-clean'] = $defaults + [
    'description' => 'Remove untracked yml files from the config directory.',
    'aliases' => ['ec-clean'],
  ];

  return $items;
}

/**
 * Drush command callback.
 */
function drush_extension_configuration_export($extension) {
  drush_extension_configuration_execute($extension, 'export');
}

/**
 * Drush command callback.
 */
function drush_extension_configuration_import($extension) {
  drush_extension_configuration_execute($extension, 'import');
}

/**
 * Drush command callback.
 */
function drush_extension_configuration_delete($extension) {
  drush_extension_configuration_execute($extension, 'delete');
}

/**
 * Drush command callback.
 */
function drush_extension_configuration_status($extension) {
  drush_extension_configuration_execute($extension, 'status', function (array $result) {
    drush_extension_configuration_print_status($result);
    if (count($result['messages']) > 0) {
      drush_print();
      drush_extension_configuration_log_result($result);
    }
  });
}

/**
 * Drush command callback.
 */
function drush_extension_configuration_clean($extension) {
  drush_extension_configuration_execute($extension, 'findUntrackedFiles', function (array $result) {
    if (count($result['files']) > 0) {
      drush_print(dt('The following files will be removed:'));
      foreach ($result['files'] as $file) {
        drush_print('- ' . $file->filename);
      }
      if (drush_confirm(dt('Do you really want to continue?'))) {
        foreach ($result['files'] as $file) {
          file_unmanaged_delete($file->uri);
        }
      }
    }
  });
}

/**
 * Executes the configuration operation.
 *
 * @param string $extension
 *   Extension to be processed.
 * @param string $operation
 *   The operation.
 *
 * @return mixed
 *   Callback result or FALSE if an exception was thrown.
 */
function drush_extension_configuration_execute($extension, $operation, callable $callback = NULL) {
  try {
    $result = Drupal::service('extension_configuration.manager')->{$operation}($extension);
    $callback = $callback ?: 'drush_extension_configuration_log_result';
    return $callback($result);
  }
  catch (Exception $exception) {
    return drush_set_error($exception->getMessage());
  }
}

/**
 * Prints command result.
 *
 * @param array $result
 *   The command result.
 */
function drush_extension_configuration_log_result(array $result) {
  foreach ($result['messages'] as $message) {
    drush_log($message[0], $message[1]);
  }
}

/**
 * Prints configuration status.
 *
 * @param array $result
 *   The result to be printed.
 */
function drush_extension_configuration_print_status(array $result) {
  /** @var \Drupal\extension_configuration\Manager $manager */
  $manager = Drupal::service('extension_configuration.manager');
  $statuses = [
    $manager::CONFIG_STATUS_DEFAULT => dt('Default'),
    $manager::CONFIG_STATUS_MISSING_IN_EXTENSION => dt('Missing in extension'),
    $manager::CONFIG_STATUS_MISSING_IN_ACTIVE_STORAGE => dt('Missing in active storage'),
    $manager::CONFIG_STATUS_OVERRIDDEN => dt('Overridden'),
  ];

  $rows[] = [
    dt('Name'),
    dt('Type'),
    dt('State'),
  ];

  $rows[] = [
    '----------',
    '----------',
    '----------',
  ];

  foreach ($result['rows'] as $row) {
    $rows[] = [
      $row[0],
      $row[1],
      $statuses[$row[2]],
    ];
  }

  drush_print_table($rows, TRUE);
}
