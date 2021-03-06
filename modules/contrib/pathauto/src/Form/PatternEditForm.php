<?php

/**
 * @file
 * Contains \Drupal\pathauto\Form\PatternEditForm.
 */

namespace Drupal\pathauto\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\pathauto\AliasTypeManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Edit form for pathauto patterns.
 */
class PatternEditForm extends EntityForm {

  /**
   * @var \Drupal\pathauto\AliasTypeManager
   */
  protected $manager;

  /**
   * @var \Drupal\pathauto\PathautoPatternInterface
   */
  protected $entity;

  /**
   * The entity type bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $entityTypeBundleInfo;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.alias_type'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity_type.manager'),
      $container->get('language_manager')
    );
  }

  /**
   * PatternEditForm constructor.
   *
   * @param \Drupal\pathauto\AliasTypeManager $manager
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   */
  function __construct(AliasTypeManager $manager, EntityTypeBundleInfoInterface $entity_type_bundle_info, EntityTypeManagerInterface $entity_type_manager, LanguageManagerInterface $language_manager) {
    $this->manager = $manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->entityTypeManager = $entity_type_manager;
    $this->languageManager= $language_manager;
  }

  /**
   * {@inheritDoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $options = [];
    foreach ($this->manager->getDefinitions() as $plugin_id => $plugin_definition) {
      $options[$plugin_id] = $plugin_definition['label'];
    }
    $form['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Pattern type'),
      '#default_value' => $this->entity->getType(),
      '#options' => $options,
      '#required' => TRUE,
      '#limit_validation_errors' => array(array('type')),
      '#submit' => array('::submitSelectType'),
      '#executes_submit_callback' => TRUE,
      '#ajax' => array(
        'callback' => '::ajaxReplacePatternForm',
        'wrapper' => 'pathauto-pattern',
        'method' => 'replace',
      ),
    ];

    $form['pattern_container'] = [
      '#type' => 'container',
      '#prefix' => '<div id="pathauto-pattern">',
      '#suffix' => '</div>',
    ];

    // if there is no type yet, stop here.
    if ($this->entity->getType()) {

      $alias_type = $this->entity->getAliasType();

      $form['pattern_container']['pattern'] = array(
        '#type' => 'textfield',
        '#title' => 'Path pattern',
        '#default_value' => $this->entity->getPattern(),
        '#size' => 65,
        '#maxlength' => 1280,
        '#element_validate' => array('token_element_validate'),
        '#after_build' => array('token_element_validate'),
        '#token_types' => $alias_type->getTokenTypes(),
        '#min_tokens' => 1,
      );

      // Show the token help relevant to this pattern type.
      $form['pattern_container']['token_help'] = array(
        '#theme' => 'token_tree_link',
        '#token_types' => $alias_type->getTokenTypes(),
      );

      // Expose bundle and language conditions.
      if ($alias_type->getDerivativeId() && $entity_type = $this->entityTypeManager->getDefinition($alias_type->getDerivativeId())) {

        $default_bundles = [];
        $default_languages = [];
        foreach ($this->entity->getSelectionConditions() as $condition_id => $condition) {
          if ($condition->getPluginId() == 'entity_bundle:' . $entity_type->id()) {
            $default_bundles = $condition->getConfiguration()['bundles'];
          }
          elseif ($condition->getPluginId() == 'language') {
            $default_languages = $condition->getConfiguration()['langcodes'];
          }
        }

        if ($entity_type->hasKey('bundle') && $bundles = $this->entityTypeBundleInfo->getBundleInfo($entity_type->id())) {
          $bundle_options = [];
          foreach ($bundles as $id => $info) {
            $bundle_options[$id] = $info['label'];
          }
          $form['pattern_container']['bundles'] = array(
            '#title' => $entity_type->getBundleLabel(),
            '#type' => 'checkboxes',
            '#options' => $bundle_options,
            '#default_value' => $default_bundles,
            '#description' => t('Check to which types this pattern should be applied. Leave empty to allow any.'),
          );
        }

        if ($this->languageManager->isMultilingual() && $entity_type->isTranslatable()) {
          $language_options = [];
          foreach ($this->languageManager->getLanguages() as $id => $language) {
            $language_options[$id] = $language->getName();
          }
          $form['pattern_container']['languages'] = array(
            '#title' => $this->t('Languages'),
            '#type' => 'checkboxes',
            '#options' => $language_options,
            '#default_value' => $default_languages,
            '#description' => t('Check to which languages this pattern should be applied. Leave empty to allow any.'),
          );
        }
      }
    }

    $form['label'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $this->entity->label(),
      '#required' => TRUE,
    );

    $form['id'] = array(
      '#type' => 'machine_name',
      '#title' => $this->t('ID'),
      '#maxlength' => 255,
      '#default_value' => $this->entity->id(),
      '#required' => TRUE,
      '#disabled' => !$this->entity->isNew(),
      '#machine_name' => array(
        'exists' => 'Drupal\pathauto\Entity\PathautoPattern::load',
      ),
    );

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritDoc}
   */
  public function buildEntity(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\pathauto\PathautoPatternInterface $entity */
    $entity = parent::buildEntity($form, $form_state);

    $default_weight = 0;

    $alias_type = $entity->getAliasType();
    if ($alias_type->getDerivativeId() && $this->entityTypeManager->hasDefinition($alias_type->getDerivativeId())) {
      $entity_type = $alias_type->getDerivativeId();
      // First, remove bundle and language conditions.
      foreach ($entity->getSelectionConditions() as $condition_id => $condition) {

        if ($condition->getPluginId() == 'entity_bundle:' . $entity_type || $condition->getPluginId() == 'language') {
          $entity->removeSelectionCondition($condition_id);
        }
      }

      if ($bundles = array_filter((array) $form_state->getValue('bundles'))) {
        $default_weight -= 5;
        $entity->addSelectionCondition(
          [
            'id' => 'entity_bundle:' . $entity_type,
            'bundles' => $bundles,
            'negate' => FALSE,
            'context_mapping' => [
              $entity_type => $entity_type,
            ]
          ]
        );
      }

      if ($languages = array_filter((array) $form_state->getValue('languages'))) {
        $default_weight -= 5;
        $language_mapping = $entity_type . ':' . $this->entityTypeManager->getDefinition($entity_type)->getKey('langcode') . ':language';
        $entity->addSelectionCondition(
          [
            'id' => 'language',
            'langcodes' => array_combine($languages, $languages),
            'negate' => FALSE,
            'context_mapping' => [
              'language' => $language_mapping,
            ]
          ]
        );
        $new_definition = new ContextDefinition('language', 'Language');
        $new_context = new Context($new_definition);
        $entity->addContext($language_mapping, $new_context);
      }

    }

    $entity->setWeight($default_weight);

    return $entity;
  }

  /**
   * {@inheritDoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    parent::save($form, $form_state);
    drupal_set_message($this->t('Pattern @label saved.', ['@label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
  }

  /**
   * Handles switching the type selector.
   */
  public function ajaxReplacePatternForm($form, FormStateInterface $form_state) {
    return $form['pattern_container'];
  }

  /**
   * Handles submit call when alias type is selected.
   */
  public function submitSelectType(array $form, FormStateInterface $form_state) {
    $this->entity = $this->buildEntity($form, $form_state);
    $form_state->setRebuild();
  }

}
