<?php

namespace Drupal\simple_voting\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form controller for VotingQuestion add/edit.
 */
class VotingQuestionForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    // Hide the default entity_reference widget to avoid validation errors
    // for free-text option titles. We'll manage choices via a textarea.
    if (isset($form['choice'])) {
      $form['choice']['#access'] = FALSE;
    }

    $entity = $this->entity;
    $default = '';
    if (!$entity->isNew() && $entity->choice) {
      $titles = [];
      foreach ($entity->choice->referencedEntities() as $opt) {
        $titles[] = $opt->label();
      }
      $default = implode("\n", $titles);
    }

    $form['choice_textarea'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Choices (one per line)'),
      '#default_value' => $default,
      '#description' => $this->t('Enter one choice title per line. You can add images later on each option.'),
      '#weight' => -90,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    // First save the question so we have an ID for options.
    $status = parent::save($form, $form_state);
    $entity = $this->entity;

    $value = $form_state->getValue('choice_textarea');
    if (is_string($value)) {
      $lines = array_filter(array_map('trim', preg_split('/\r?\n/', $value)), function($v) { return $v !== ''; });
      $storage = \Drupal::entityTypeManager()->getStorage('voting_option');
      $target_ids = [];
      foreach ($lines as $title) {
        // Try reuse an existing option for this question with same title.
        $matches = $storage->loadByProperties(['title' => $title, 'question_id' => $entity->id()]);
        if ($matches) {
          $opt = reset($matches);
          $target_ids[] = $opt->id();
          continue;
        }

        $opt = $storage->create([
          'title' => $title,
          'question_id' => $entity->id(),
        ]);
        $opt->save();
        $target_ids[] = $opt->id();
      }

      $refs = [];
      foreach ($target_ids as $tid) {
        $refs[] = ['target_id' => $tid];
      }
      $entity->set('choice', $refs);
      $entity->save();
    }

    // Redirect to canonical after save.
    $form_state->setRedirect('entity.voting_question.canonical', ['voting_question' => $entity->id()]);
  }

}
