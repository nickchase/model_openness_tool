<?php declare(strict_types=1);

namespace Drupal\mof;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserStorageInterface;
use Drupal\user\UserInterface;
use Drupal\mof\Entity\Model;
use Drupal\mof\ModelInterface;

/**
 * @file
 * Creates or updates model entities with provided data.
 * Typically used to sync data in models/ directory.
 */
final class ModelUpdater {

  /** @var \Drupal\Core\Entity\EntityStorageInterface. */
  private readonly EntityStorageInterface $modelStorage;

  /** @var \Drupal\user\UserStorageInterface. */
  private readonly UserStorageInterface $userStorage;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   * @param \Drupal\mof\ComponentManagerInterface $componentManager
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ComponentManagerInterface $componentManager
  ) {
    $this->modelStorage = $entityTypeManager->getStorage('model');
    $this->userStorage = $entityTypeManager->getStorage('user');
  }

  /**
   * Check if a model exists by its name/label.
   *
   * @param array $model_data
   *   The model data in array format.
   * @return \Drupal\mof\ModelInterface|NULL
   *   The model if it exists; NULL otherwise.
   */
  public function exists(array $model_data): ?ModelInterface {
    $model = $this->modelStorage->loadByProperties(['label' => $model_data['name']]);
    return empty($model) ? NULL : reset($model);
  }

  /**
   * Update a model entity with supplied model data.
   *
   * @param \Drupal\mof\ModelInterface $model
   *   The model entity that will be updated.
   * @param array $model_data
   *   The model data we are updating $model with.
   * @param int
   *   Either SAVED_NEW or SAVED_UPDATED.
   */
  public function update(ModelInterface $model, array $model_data): int {
    $license_data = [];

    foreach ($model_data as $field => $value) {
      // @todo Rename these fields on the entity(?)
      if ($field === 'name') $field = 'label';
      if ($field === 'producer') $field = 'organization';

      if ($field === 'license') {
        $license_data['global'] = $value;
      }
      else if ($field === 'components') {
        $license_data['components'] = $this->processComponentLicenses($value);
      }
      // @todo Replace owner reference field with a contact text field.
      else if ($field === 'contact') {
        $model->set('uid', 1);
      }
      else if ($field === 'date') {
        $model->set('changed', strtotime($value));
      }
      else {
        $model->set($field, $value);
      }
    }

    // Set license and component data.
    $model->set('license_data', ['licenses' => $license_data]);
    $model->set('components', array_keys($license_data['components']));

    // Model is auto-approved.
    $model->setStatus(Model::STATUS_APPROVED);

    return $model->save();
  }

  /**
   * Create a model entity.
   *
   * @param array $model_data
   *   The model data.
   * @return int *   Should always be SAVED_NEW.
   */
  public function create(array $model_data): int {
    $model = $this->modelStorage->create();
    return $this->update($model, $model_data);
  }

  /**
   * Process licenses for each component of the model.
   *
   * @param array $license_data
   *   The license data to process for each component.
   * @return array
   *   The license array structured for a model entity.
   */
  private function processComponentLicenses(array $license_data): array {
    $licenses = [];

    foreach ($license_data as $component_data) {
      $component = $this
        ->componentManager
        ->getComponentByName($component_data['name']);

      $licenses[$component->id] = [];

      foreach (['license', 'license_path', 'component_path'] as $key) {
        if (isset($component_data[$key])) {
          // Set key but leave blank if unlicensed.
          if ($key === 'license' && $component_data[$key] === 'unlicensed') {
            $licenses[$component->id][$key] = '';
          }
          else {
            $licenses[$component->id][$key] = $component_data[$key];
          }
        }
      }
    }

    return $licenses;
  }

}

