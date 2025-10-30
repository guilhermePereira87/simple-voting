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

    $entity = $this->entity;
    $default = '';
    if (!$entity->isNew()) {
      $storage = \Drupal::entityTypeManager()->getStorage('voting_option');
  $optIds = $storage->getQuery()->condition('question_id', $entity->id())->accessCheck(FALSE)->execute();
      if (!empty($optIds)) {
        $optEntities = $storage->loadMultiple($optIds);
        $titles = [];
        foreach ($optEntities as $optEntity) {
          $titles[] = $optEntity->label();
        }
        $default = implode("\n", $titles);
      }
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
  $targetIds = [];
  foreach ($lines as $title) {
        // Try reuse an existing option for this question with same title.
        $matches = $storage->loadByProperties(['title' => $title, 'question_id' => $entity->id()]);
        if ($matches) {
          $opt = reset($matches);
          $targetIds[] = $opt->id();
          continue;
        }

        $opt = $storage->create([
          'title' => $title,
          'question_id' => $entity->id(),
        ]);
        $opt->save();
        $targetIds[] = $opt->id();
      }

      $refs = [];
      foreach ($targetIds as $targetId) {
        $refs[] = ['target_id' => $targetId];
      }
  // Options are created with question_id; we do not mirror refs on the
  // question entity.
    }

    // Redirect to canonical after save.
    $form_state->setRedirect('entity.voting_question.canonical', ['voting_question' => $entity->id()]);
  }

}
