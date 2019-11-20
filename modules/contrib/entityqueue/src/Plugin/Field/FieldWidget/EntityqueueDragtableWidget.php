<?php

namespace Drupal\entityqueue\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\EntityReferenceAutocompleteWidget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;

/**
 * Plugin implementation of the 'entityqueue_dragtable' widget.
 *
 * @FieldWidget(
 *   id = "entityqueue_dragtable",
 *   label = @Translation("Autocomplete (draggable table)"),
 *   description = @Translation("An autocomplete text field with a draggable table."),
 *   field_types = {
 *     "entity_reference"
 *   }
 * )
 */
class EntityqueueDragtableWidget extends EntityReferenceAutocompleteWidget {

  /**
   * The unique HTML ID of the widget's wrapping element.
   *
   * @var string
   */
  protected $wrapperId;

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'link_to_entity' => FALSE,
      'link_to_edit_form' => TRUE,
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = parent::settingsForm($form, $form_state);

    $elements['link_to_entity'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Link label to the referenced entity'),
      '#default_value' => $this->getSetting('link_to_entity'),
    ];
    $elements['link_to_edit_form'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Add a link to the edit form of the referenced entity'),
      '#default_value' => $this->getSetting('link_to_edit_form'),
    ];

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();

    $settings = $this->getSettings();
    if (!empty($settings['link_to_entity'])) {
      $summary[] = $this->t('Link to the referenced entity');
    }
    if (!empty($settings['link_to_edit_form'])) {
      $summary[] = $this->t('Link to the edit form of the referenced entity');
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function form(FieldItemListInterface $items, array &$form, FormStateInterface $form_state, $get_delta = NULL) {
    if ($this->fieldDefinition->getTargetEntityTypeId() === 'entity_subqueue' && $this->fieldDefinition->getName() === 'items') {
      // Restrict the cardinality of the 'items' field if the queue has defined
      // a maximum number of items and it is not configured to act as a queue.
      /** @var \Drupal\entityqueue\EntityQueueInterface $queue */
      $queue = $items->getEntity()->getQueue();
      if (($max_size = $queue->getMaximumSize()) && !$queue->getActAsQueue()) {
        $this->fieldDefinition->getFieldStorageDefinition()->setCardinality($max_size);
      }
    }

    return parent::form($items, $form, $form_state, $get_delta);
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    assert($items instanceof EntityReferenceFieldItemListInterface);
    /** @var \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository */
    $entity_repository = \Drupal::service('entity.repository');

    $referenced_entities = $items->referencedEntities();
    $field_name = $this->fieldDefinition->getName();

    if (isset($referenced_entities[$delta])) {
      $entity = $entity_repository->getTranslationFromContext($referenced_entities[$delta]);
      $entity_label = ($this->getSetting('link_to_entity') && !$entity->isNew()) ? $entity->toLink()->toString() : $entity->label();

      $element += [
        '#type' => 'container',
        '#attributes' => ['class' => ['form--inline']],
        'target_id' => [
          '#type' => 'item',
          '#markup' => ($entity->access('view label')) ? $entity_label : t('- Restricted access -'),
          '#default_value' => !$referenced_entities[$delta]->isNew() ? $referenced_entities[$delta]->id() : NULL,
          '#weight' => 0,
        ],
        '_edit' => $referenced_entities[$delta]->toLink($this->t('Edit'), 'edit-form', ['query' => \Drupal::destination()->getAsArray()])->toRenderable() + [
          '#attributes' => ['class' => ['form-item']],
          '#access' => (bool) $this->getSetting('link_to_edit_form'),
        ],
        '_remove' => [
          '#type' => 'submit',
          '#name' => implode('_', array_merge($form['#parents'], [$field_name, $delta])) . '_remove',
          '#delta' => $delta,
          '#value' => $this->t('Remove'),
          '#attributes' => ['class' => ['remove-item-submit', 'align-right']],
          '#limit_validation_errors' => [array_merge($form['#parents'], [$field_name])],
          '#submit' => [[get_class($this), 'removeSubmit']],
          '#ajax' => [
            'callback' => [get_class($this), 'removeAjax'],
            'wrapper' => $this->getWrapperId(),
            'effect' => 'fade',
          ],
        ],
      ];
    }

    return $element;
  }

  /**
   * Submission handler for the "Remove" button.
   */
  public static function removeSubmit(array &$form, FormStateInterface $form_state) {
    $button = $form_state->getTriggeringElement();

    // Go two levels up in the form, to the widgets container.
    $element = NestedArray::getValue($form, array_slice($button['#array_parents'], 0, -2));
    $field_name = $element['#field_name'];
    $parents = $element['#field_parents'];

    // Set a NULL target ID for the removed element.
    $form_state->setValueForElement($element[$button['#delta']]['target_id'], NULL);

    // Update the field item list values.
    $items = $form_state->getFormObject()->getEntity()->get($field_name);
    $widget = $form_state->getFormObject()->getFormDisplay($form_state)->getRenderer($field_name);
    $widget->extractFormValues($items, $form, $form_state);

    // Remove unneeded properties.
    foreach ($items as $item) {
      unset($item->_remove);
    }

    // Decrease the items count.
    $field_state = static::getWidgetState($parents, $field_name, $form_state);
    $field_state['items_count']--;
    static::setWidgetState($parents, $field_name, $form_state, $field_state);

    $form_state->setRebuild();
  }

  /**
   * Ajax callback for the "Remove" button.
   */
  public static function removeAjax(array $form, FormStateInterface $form_state) {
    $button = $form_state->getTriggeringElement();

    // Go two levels up in the form, to the widget container.
    $element = NestedArray::getValue($form, array_slice($button['#array_parents'], 0, -2));
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  protected function formMultipleElements(FieldItemListInterface $items, array &$form, FormStateInterface $form_state) {
    $field_name = $this->fieldDefinition->getName();
    $cardinality = $this->fieldDefinition->getFieldStorageDefinition()->getCardinality();
    $parents = $form['#parents'];

    // Assign a unique identifier to each widget.
    $id_prefix = implode('-', array_merge($parents, [$field_name]));
    $wrapper_id = Html::getUniqueId($id_prefix . '-wrapper');
    $this->setWrapperId($wrapper_id);

    $elements = parent::formMultipleElements($items, $form, $form_state);
    $elements['#cardinality_multiple'] = TRUE;

    // Remove empty elements generated by the parent method.
    foreach ($elements as $key => $element) {
      if (Element::child($key) && is_numeric($key) && !isset($element['target_id'])) {
        unset($elements[$key]);
      }
    }
    $items->filterEmptyItems();
    $elements['#max_delta'] = count($items) - 1;

    $field_state = static::getWidgetState($parents, $field_name, $form_state);
    if ($cardinality === FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED || $field_state['items_count'] < $cardinality) {
      $elements['add_more'] = [
        '#type' => 'container',
        '#tree' => TRUE,
        '#attributes' => ['class' => ['form--inline']],
        'new_item' => parent::formElement($items, -1, [], $form, $form_state),
        'submit' => [
          '#type' => 'submit',
          '#name' => strtr($id_prefix, '-', '_') . '_add_more',
          '#value' => $this->t('Add item'),
          '#attributes' => ['class' => ['field-add-more-submit']],
          '#limit_validation_errors' => [array_merge($parents, [$field_name])],
          '#submit' => [[get_class($this), 'addItemSubmit']],
          '#ajax' => [
            'callback' => [get_class($this), 'addItemAjax'],
            'wrapper' => $this->getWrapperId(),
            'effect' => 'fade',
          ],
        ],
      ];
    }

    // Update the HTML wrapper ID with the one generated by us.
    $elements['#prefix'] = '<div id="' . $this->getWrapperId() . '">';
    $elements['#suffix'] = '</div>';

    return $elements;
  }

  /**
   * Submission handler for the "Add item" button.
   */
  public static function addItemSubmit(array $form, FormStateInterface $form_state) {
    $button = $form_state->getTriggeringElement();

    // Go two levels up in the form, to the widgets container.
    $element = NestedArray::getValue($form, array_slice($button['#array_parents'], 0, -2));
    $field_name = $element['#field_name'];
    $parents = $element['#field_parents'];

    $submitted_values = NestedArray::getValue($form_state->getValues(), array_slice($button['#parents'], 0, -1));
    $items = $form_state->getFormObject()->getEntity()->get($field_name);
    $items->appendItem($submitted_values['new_item']);

    // Increment the items count.
    $field_state = static::getWidgetState($parents, $field_name, $form_state);
    $field_state['items_count']++;
    static::setWidgetState($parents, $field_name, $form_state, $field_state);

    $form_state->setRebuild();
  }

  /**
   * Ajax callback for the "Add item" button.
   */
  public static function addItemAjax(array $form, FormStateInterface $form_state) {
    $button = $form_state->getTriggeringElement();

    // Go two levels up in the form, to the widgets container.
    $element = NestedArray::getValue($form, array_slice($button['#array_parents'], 0, -2));

    // Remove the submitted value from the 'Add item' textfield.
    $element['add_more']['new_item']['target_id']['#value'] = NULL;

    // Add a DIV around the delta receiving the Ajax effect.
    $delta = $element['#max_delta'];
    $element[$delta]['#prefix'] = '<div class="ajax-new-content">' . (isset($element[$delta]['#prefix']) ? $element[$delta]['#prefix'] : '');
    $element[$delta]['#suffix'] = (isset($element[$delta]['#suffix']) ? $element[$delta]['#suffix'] : '') . '</div>';

    return $element;
  }

  /**
   * Sets the unique HTML ID of the widget's wrapping element.
   *
   * @param string $wrapperId
   *   The unique HTML ID.
   */
  public function setWrapperId($wrapperId) {
    if (!$this->wrapperId) {
      $this->wrapperId = $wrapperId;
    }
  }

  /**
   * Gets the unique HTML ID of the widget's wrapping element.
   *
   * @return string
   *   The unique HTML ID.
   */
  public function getWrapperId() {
    return $this->wrapperId;
  }

}
