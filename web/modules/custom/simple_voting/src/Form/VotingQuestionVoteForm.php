<?php

namespace Drupal\simple_voting\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\simple_voting\Entity\VotingQuestion;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Render\Markup;

/**
 * Form to submit a vote for a VotingQuestion.
 */
class VotingQuestionVoteForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'simple_voting_vote_form';
  }

  /**
   * Build the vote form.
   *
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   * @param \Drupal\simple_voting\Entity\VotingQuestion|null $question
   *   The question entity passed from the view builder.
   */
  public function buildForm(array $form, FormStateInterface $form_state, VotingQuestion $question = NULL) {
    if (!$question) {
      return ['#markup' => $this->t('No question provided.')];
    }

  $currentUser = \Drupal::currentUser();

    // Ensure only authenticated users can vote (adjust if anonymous voting is desired).
    if (!$currentUser->isAuthenticated()) {
      $form['login_notice'] = [
        '#markup' => $this->t('You must be logged in to vote.'),
        '#weight' => -10,
      ];
      return $form;
    }

    // Check if the user already voted.
    $recordStorage = \Drupal::entityTypeManager()->getStorage('voting_record');
    $existing = $recordStorage->loadByProperties([
      'question_id' => $question->id(),
      'uid' => $currentUser->id(),
    ]);
    $hasVoted = !empty($existing);

    // If user has voted and results should be shown, display results and stop.
  if ($hasVoted && $question->hasField('show_results') && $question->get('show_results')->value) {
      // Count votes grouped by option.
      $db = \Drupal::database();
      $res = $db->select('voting_record', 'vr')
        ->fields('vr', ['option_id'])
        ->condition('vr.question_id', $question->id())
        ->addExpression('COUNT(vr.id)', 'count')
        ->groupBy('vr.option_id')
        ->execute();
      $counts = [];
      foreach ($res as $row) {
        $counts[$row->option_id] = (int) $row->count;
      }

      $items = [];
      $optionStorage = \Drupal::entityTypeManager()->getStorage('voting_option');
      $optIds = $optionStorage->getQuery()
        ->condition('question_id', $question->id())
        ->accessCheck(FALSE)
        ->sort('id', 'ASC')
        ->execute();
      $options = $optionStorage->loadMultiple($optIds);
      foreach ($options as $optEntity) {
        $title = Xss::filterAdmin($optEntity->label());
        $count = $counts[$optEntity->id()] ?? 0;
        $items[] = Markup::create('<div class="voting-result"><div class="voting-result-title">' . $title . '</div><div class="voting-result-count">' . $this->t('@count votes', ['@count' => $count]) . '</div></div>');
      }

      $form['results'] = [
        '#theme' => 'item_list',
        '#items' => $items,
        '#attributes' => ['class' => ['voting-results']],
      ];

      return $form;
    }

  if ($hasVoted) {
      // User has voted but results are disabled. Show a thank you message.
      $form['message'] = ['#markup' => $this->t('Thanks for voting.')];
      return $form;
    }

    // Build radio options for voting.
    $optionStorage = \Drupal::entityTypeManager()->getStorage('voting_option');
    $optIds = $optionStorage->getQuery()
      ->condition('question_id', $question->id())
      ->accessCheck(FALSE)
      ->sort('id', 'ASC')
      ->execute();
    $options = $optionStorage->loadMultiple($optIds);

    $radios = [];
    foreach ($options as $optEntity) {
      $label = Xss::filterAdmin($optEntity->label());
      // Add image markup if present.
      if ($optEntity->hasField('image') && !$optEntity->get('image')->isEmpty()) {
        $fid = $optEntity->get('image')->target_id;
        $file = \Drupal::entityTypeManager()->getStorage('file')->load($fid);
        if ($file) {
          $uri = $file->getFileUri();
          $url = \Drupal::service('file_url_generator')->generateAbsoluteString($uri);
          $label = '<img src="' . $url . '" class="voting-option-image" alt="' . Xss::filterAdmin($label) . '"/> ' . $label;
        }
      }
      $radios[$optEntity->id()] = Markup::create($label);
    }

    $form['question_id'] = [
      '#type' => 'value',
      '#value' => $question->id(),
    ];

    $form['option'] = [
      '#type' => 'radios',
      '#title' => $this->t('Options'),
      '#options' => $radios,
      '#required' => TRUE,
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Vote'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $questionId = $form_state->getValue('question_id');
    $optionVal = $form_state->getValue('option');
    // Basic validation: ensure option exists and belongs to the provided question.
    if (empty($optionVal) || empty($questionId)) {
      $form_state->setErrorByName('option', $this->t('Please select an option.'));
      return;
    }
    $optionStorage = \Drupal::entityTypeManager()->getStorage('voting_option');
    $option = $optionStorage->load($optionVal);
    if (!$option || (int) $option->get('question_id')->target_id !== (int) $questionId) {
      $form_state->setErrorByName('option', $this->t('Invalid option selected.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
  $questionId = $form_state->getValue('question_id');
  $optionVal = $form_state->getValue('option');
  $currentUser = \Drupal::currentUser();

    // Prevent double-voting using a uniqueness check (the entity also has a constraint).
    $recordStorage = \Drupal::entityTypeManager()->getStorage('voting_record');
    $existing = $recordStorage->loadByProperties([
      'question_id' => $questionId,
      'uid' => $currentUser->id(),
    ]);
    if (!empty($existing)) {
      $this->messenger()->addWarning($this->t('You have already voted on this question.'));
      // Redirect back to the question.
      $form_state->setRedirect('entity.voting_question.canonical', ['voting_question' => $questionId]);
      return;
    }
    $record = $recordStorage->create([
      'question_id' => $questionId,
      'option_id' => $optionVal,
      'uid' => $currentUser->id(),
    ]);
    $record->save();
    $this->messenger()->addStatus($this->t('Your vote has been recorded.'));
    $form_state->setRedirect('entity.voting_question.canonical', ['voting_question' => $questionId]);
  }

}
