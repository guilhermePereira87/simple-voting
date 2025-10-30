<?php

namespace Drupal\simple_voting\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Edit form for VotingQuestion that loads linked voting_option entities.
 */
class VotingQuestionEditForm extends ContentEntityForm {

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

    // Published checkbox so editors can toggle publish state while editing.
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

    /** @var \Drupal\simple_voting\Entity\VotingQuestion $question */
    $question = $this->entity;
    // Load existing options for this question by querying voting_option where
    // question_id == $question->id().
    $existing = [];
    if (!$question->isNew()) {
      $optionStorage = $this->entityTypeManager->getStorage('voting_option');
      $optIds = $optionStorage->getQuery()->condition('question_id', $question->id())->accessCheck(FALSE)->sort('id', 'ASC')->execute();
      if (!empty($optIds)) {
        $options = $optionStorage->loadMultiple($optIds);
        foreach ($options as $optEntity) {
          /** @var \Drupal\simple_voting\Entity\VotingOption $opt */
          $fid = NULL;
          if ($optEntity->hasField('image') && !$optEntity->get('image')->isEmpty()) {
            $fid = $optEntity->get('image')->target_id;
          }
          $text = '';
          if ($optEntity->hasField('text') && !$optEntity->get('text')->isEmpty()) {
            $text = $optEntity->get('text')->value;
          }
          $existing[] = [
            'id' => $optEntity->id(),
            'title' => $optEntity->label(),
            'image' => $fid ? [$fid] : [],
            'description' => $text,
          ];
        }
      }
    }

    $num_existing = count($existing);
    $num = $form_state->get('num_options');
    if ($num === NULL) {
      // Start with existing options + 1 empty row for convenience.
      $num = max(1, $num_existing + 1);
      $form_state->set('num_options', $num);
    }

    $form['options'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'voting-options-wrapper'],
      '#tree' => TRUE,
    ];

    for ($i = 0; $i < $num; $i++) {
      $defaults = $existing[$i] ?? ['id' => NULL, 'title' => '', 'image' => []];
      $form['options'][$i] = [
        '#type' => 'details',
        '#title' => $this->t('Option @n', ['@n' => $i + 1]),
        '#open' => TRUE,
      ];

      if (!empty($defaults['id'])) {
        $form['options'][$i]['option_id'] = [
          '#type' => 'hidden',
          '#default_value' => $defaults['id'],
        ];
      }

      $form['options'][$i]['title'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Option title'),
        '#default_value' => $defaults['title'],
        '#required' => FALSE,
      ];

      $form['options'][$i]['description'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Option description (optional)'),
        '#default_value' => $defaults['description'] ?? '',
        '#required' => FALSE,
      ];

      $form['options'][$i]['image'] = [
        '#type' => 'managed_file',
        '#title' => $this->t('Image (optional)'),
        '#upload_location' => 'public://voting_option_images/',
        '#description' => $this->t('Allowed extensions: png jpg jpeg gif'),
        '#multiple' => FALSE,
        '#default_value' => $defaults['image'],
      ];
    }

    $form['add_option'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add another option'),
      '#submit' => ['::addOne'],
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => '::ajaxCallback',
        'wrapper' => 'voting-options-wrapper',
      ],
    ];

    return $form;
  }

  /**
   * Ajax callback for add-more.
   */
  public function ajaxCallback(array &$form, FormStateInterface $form_state) {
    return $form['options'];
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
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    // Apply published state from form before saving the entity.
    $status = $form_state->getValue('status');
    $this->entity->set('status', (bool) $status);

    // Save the question entity first.
    parent::save($form, $form_state);
    $question = $this->entity;

    $submitted = $form_state->getValue('options') ?? [];
    $optionStorage = $this->entityTypeManager->getStorage('voting_option');
    $fileStorage = $this->entityTypeManager->getStorage('file');
    $fileUsage = $this->fileUsage;

    // Track IDs we keep/create so we can update the question references.
    $kept = [];

    foreach ($submitted as $row) {
      $optId = $row['option_id'] ?? NULL;
      $title = trim($row['title'] ?? '');
      $fid = NULL;
      if (!empty($row['image']) && is_array($row['image'])) {
        $fid = reset($row['image']);
      }

      if ($optId) {
        $opt = $optionStorage->load($optId);
        /** @var \Drupal\simple_voting\Entity\VotingOption $opt */
        if (!$opt) {
          // If referenced id doesn't exist, skip.
          continue;
        }

        // If title is missing, treat as delete request (title is required).
        if ($title === '') {
          // Remove file usage if any, then delete the option.
          if ($opt->hasField('image') && !$opt->get('image')->isEmpty()) {
            $old_fid = $opt->get('image')->target_id;
            if ($old_fid) {
              $old_file = $fileStorage->load($old_fid);
              if ($old_file) {
                $fileUsage->delete($old_file, 'simple_voting', 'voting_option', $opt->id());
              }
            }
          }
          $opt->delete();
          continue;
        }

        // Update title (required at this point).
        $opt->set('title', $title);
        // Update description if provided.
        $desc = trim($row['description'] ?? '');
        if ($opt->hasField('text')) {
          $opt->set('text', [['value' => $desc]]);
        }

        // Handle image replacement/setting when a new file was uploaded.
        if (!empty($fid)) {
          $file = $fileStorage->load($fid);
          if ($file instanceof File) {
            $allowed = ['png', 'jpg', 'jpeg', 'gif'];
            $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
            if (in_array($ext, $allowed, TRUE)) {
              // Remember any old fid so we can remove usage if replaced.
              $old_fid = NULL;
              if ($opt->hasField('image') && !$opt->get('image')->isEmpty()) {
                $old_fid = $opt->get('image')->target_id;
              }
              $file->setPermanent();
              $file->save();
              $opt->set('image', [['target_id' => $file->id()]]);
              if (!empty($old_fid) && $old_fid != $file->id()) {
                $old_file = $fileStorage->load($old_fid);
                if ($old_file) {
                  $fileUsage->delete($old_file, 'simple_voting', 'voting_option', $opt->id());
                }
              }
              $fileUsage->add($file, 'simple_voting', 'voting_option', $opt->id());
            }
          }
        }

        $opt->save();
        $kept[] = $opt->id();
      }
      else {
        // Create new option only when title is provided (image is optional).
        if ($title === '') {
          continue;
        }
        $fields = [
          'title' => $title,
          'question_id' => $question->id(),
        ];
        if (!empty($fid)) {
          $file = $fileStorage->load($fid);
          if ($file instanceof File) {
            $allowed = ['png', 'jpg', 'jpeg', 'gif'];
            $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
            if (in_array($ext, $allowed, TRUE)) {
              $file->setPermanent();
              $file->save();
              $fields['image'] = [['target_id' => $file->id()]];
            }
            else {
              // If uploaded file has disallowed extension, ignore the image but still
              // allow creating/updating the option based on title.
              $file = NULL;
            }
          }
        }

        $new = $optionStorage->create($fields);
        $new->save();
        if (!empty($file) && $file instanceof File) {
          $fileUsage->add($file, 'simple_voting', 'voting_option', $new->id());
        }
        // Save optional description for newly created option if provided.
        $desc = trim($row['description'] ?? '');
        if ($desc !== '') {
          $new->set('text', [['value' => $desc]]);
          $new->save();
        }
        $kept[] = $new->id();
      }

    }

    // We don't maintain a mirrored 'choice' field on the question. Options
    // have been created/updated/deleted above with the proper 'question_id'.
    $this->messenger()->addMessage($this->t('Voting question saved with @count options.', ['@count' => count($kept)]));
    $form_state->setRedirect('entity.voting_question.canonical', ['voting_question' => $question->id()]);
  }

}
