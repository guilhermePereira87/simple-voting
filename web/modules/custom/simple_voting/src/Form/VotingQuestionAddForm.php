<?php

namespace Drupal\simple_voting\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;

/**
 * Add-only form for VotingQuestion with add-more option rows (title + optional image).
 */
class VotingQuestionAddForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    // If this is not a new entity, fall back to the standard form.
    if (!empty($this->entity) && !$this->entity->isNew()) {
      return $form;
    }

    // Hide the core 'choice' widget so we can populate it programmatically.
    if (isset($form['choice'])) {
      $form['choice']['#access'] = FALSE;
    }

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
    // Save the question first to ensure it has an ID for option references.
    parent::save($form, $form_state);
    $question = $this->entity;

    $options = $form_state->getValue('options') ?? [];
    if (empty($options) || !is_array($options)) {
      // Nothing to do.
      $form_state->setRedirect('entity.voting_question.canonical', ['voting_question' => $question->id()]);
      return;
    }

    $option_storage = \Drupal::entityTypeManager()->getStorage('voting_option');
    $file_storage = \Drupal::entityTypeManager()->getStorage('file');
    $file_usage = \Drupal::service('file.usage');
    $created_ids = [];

    foreach ($options as $opt) {
      $title = trim($opt['title'] ?? '');
      if ($title === '') {
        continue;
      }

      $fields = [
        'title' => $title,
        'question_id' => $question->id(),
      ];

      $fid = NULL;
      $file = NULL;
      if (!empty($opt['image']) && is_array($opt['image'])) {
        $fid = reset($opt['image']);
      }

      if ($fid) {
        $file = $file_storage->load($fid);
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
            \Drupal::logger('simple_voting')->warning('Discarding uploaded file %name due to disallowed extension.', ['%name' => $file->getFilename()]);
            $file = NULL;
          }
        }
      }

      $option = $option_storage->create($fields);
      $option->save();

      if ($file instanceof File) {
        $file_usage->add($file, 'simple_voting', 'voting_option', $option->id());
      }

      $created_ids[] = $option->id();
    }

    if (!empty($created_ids)) {
      $refs = [];
      foreach ($created_ids as $id) {
        $refs[] = ['target_id' => $id];
      }
      $question->set('choice', $refs);
      $question->save();
    }

    \Drupal::messenger()->addMessage($this->t('Voting question created with @count options.', ['@count' => count($created_ids)]));

    $form_state->setRedirect('entity.voting_question.canonical', ['voting_question' => $question->id()]);
  }

}
