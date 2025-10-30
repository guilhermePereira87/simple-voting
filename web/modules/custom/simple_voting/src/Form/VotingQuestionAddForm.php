<?php

namespace Drupal\simple_voting\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Add-only form for VotingQuestion with add-more option rows (title + optional image).
 */
class VotingQuestionAddForm extends ContentEntityForm {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * File usage service.
   *
   * @var \Drupal\file\FileUsage\FileUsageInterface
   */
  protected $fileUsage;

  /**
   * Logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->fileUsage = $container->get('file.usage');
    $instance->logger = $container->get('logger.factory')->get('simple_voting');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    // If this is not a new entity, fall back to the standard form.
    if (!empty($this->entity) && !$this->entity->isNew()) {
      return $form;
    }

    // Published checkbox so creators can publish/unpublish at creation time.
    $default_published = 1;
    if (!empty($this->entity) && $this->entity->hasField('status') && !$this->entity->get('status')->isEmpty()) {
      $default_published = (bool) $this->entity->get('status')->value;
    }
    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Published'),
      '#default_value' => $default_published,
      '#weight' => -90,
    ];

    // We manage options directly on the voting_option entity via question_id.
    $num = $form_state->get('num_options');
    if ($num === NULL) {
      $num = 1;
      $form_state->set('num_options', $num);
    }

    $form['options'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'voting-options-wrapper'],
      '#tree' => TRUE,
    ];

    for ($i = 0; $i < $num; $i++) {
      $form['options'][$i] = [
        '#type' => 'details',
        '#title' => $this->t('Option @n', ['@n' => $i + 1]),
        '#open' => TRUE,
      ];

      $form['options'][$i]['title'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Option title'),
        '#required' => FALSE,
      ];

      $form['options'][$i]['description'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Option description (optional)'),
        '#description' => $this->t('A short description for this option.'),
        '#required' => FALSE,
      ];

      $form['options'][$i]['image'] = [
        '#type' => 'managed_file',
        '#title' => $this->t('Image (optional)'),
        '#upload_location' => 'public://voting_option_images/',
        '#description' => $this->t('Allowed extensions: png jpg jpeg gif'),
        '#multiple' => FALSE,
      ];
    }

    $form['add_option'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add another option'),
      '#submit' => ['::addOne'],
      // Prevent full form validation when adding more rows.
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => '::ajaxCallback',
        'wrapper' => 'voting-options-wrapper',
      ],
    ];

    return $form;
  }

  /**
   * Submit handler to add one more option row and rebuild the form.
   */
  public function addOne(array &$form, FormStateInterface $form_state) {
    $num = $form_state->get('num_options') ?: 0;
    $form_state->set('num_options', $num + 1);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Ajax callback for add-more.
   */
  public function ajaxCallback(array &$form, FormStateInterface $form_state) {
    return $form['options'];
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    // Ensure the published state from the form is applied before the entity
    // is saved so the initial save includes the desired status.
    $status = $form_state->getValue('status');
    // Set the status field value directly. VotingQuestion implements the
    // published convention, so writing the 'status' base field is sufficient.
    $this->entity->set('status', (bool) $status);

    // Save the question first to ensure it has an ID for option references.
    parent::save($form, $form_state);
    $question = $this->entity;

    $options = $form_state->getValue('options') ?? [];
    if (empty($options) || !is_array($options)) {
      // Nothing to do.
      $form_state->setRedirect('entity.voting_question.canonical', ['voting_question' => $question->id()]);
      return;
    }

    $optionStorage = $this->entityTypeManager->getStorage('voting_option');
    $fileStorage = $this->entityTypeManager->getStorage('file');
    $fileUsage = $this->fileUsage;
    $createdIds = [];

    foreach ($options as $optionData) {
      $title = trim($optionData['title'] ?? '');
      if ($title === '') {
        continue;
      }

      $fields = [
        'title' => $title,
        'question_id' => $question->id(),
      ];

      // Optional description text.
      $text = trim($optionData['description'] ?? '');
      if ($text !== '') {
        $fields['text'] = [['value' => $text]];
      }

      $fid = NULL;
      $file = NULL;
      if (!empty($optionData['image']) && is_array($optionData['image'])) {
        $fid = reset($optionData['image']);
      }

      if ($fid) {
        $file = $fileStorage->load($fid);
        if ($file instanceof File) {
          // Validate extension manually to avoid relying on upload validator plugin.
          $allowed = ['png', 'jpg', 'jpeg', 'gif'];
          $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
          if (in_array($ext, $allowed, TRUE)) {
            $file->setPermanent();
            $file->save();
            $fields['image'] = [['target_id' => $file->id()]];
          }
          else {
            $this->logger->warning('Discarding uploaded file %name due to disallowed extension.', ['%name' => $file->getFilename()]);
            $file = NULL;
          }
        }
      }

      $option = $optionStorage->create($fields);
      $option->save();

      if ($file instanceof File) {
        $fileUsage->add($file, 'simple_voting', 'voting_option', $option->id());
      }

      $createdIds[] = $option->id();
    }

    // Options are already created with 'question_id' set; no mirrored
    // references are maintained on the question entity.
    $this->messenger()->addMessage($this->t('Voting question created with @count options.', ['@count' => count($createdIds)]));

    $form_state->setRedirect('entity.voting_question.canonical', ['voting_question' => $question->id()]);
  }

}
