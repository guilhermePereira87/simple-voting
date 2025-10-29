<?php

namespace Drupal\simple_voting\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\simple_voting\Entity\VotingQuestion;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Render\Markup;

/**
 * Form to submit a vote and create a VotingRecord entity.
 */
class VoteRecordForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'simple_voting_vote_record_form';
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

    $current_user = \Drupal::currentUser();

    // Ensure only authenticated users can vote (adjust if anonymous voting is desired).
    if (!$current_user->isAuthenticated()) {
      $form['login_notice'] = [
        '#markup' => $this->t('You must be logged in to vote.'),
        '#weight' => -10,
      ];
      return $form;
    }

    // Check if the user already voted.
    $record_storage = \Drupal::entityTypeManager()->getStorage('voting_record');
    $existing = $record_storage->loadByProperties([
      'question_id' => $question->id(),
      'uid' => $current_user->id(),
    ]);
    $has_voted = !empty($existing);

    // If user has voted and results should be shown, display results and stop.
    if ($has_voted && $question->hasField('show_results') && $question->get('show_results')->value) {
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
      $option_storage = \Drupal::entityTypeManager()->getStorage('voting_option');
      $ids = $option_storage->getQuery()
        ->condition('question_id', $question->id())
        ->accessCheck(FALSE)
        ->sort('id', 'ASC')
        ->execute();
      $options = $option_storage->loadMultiple($ids);
      foreach ($options as $opt) {
        $title = Xss::filterAdmin($opt->label());
        $count = $counts[$opt->id()] ?? 0;
        $items[] = Markup::create('<div class="voting-result"><div class="voting-result-title">' . $title . '</div><div class="voting-result-count">' . $this->t('@count votes', ['@count' => $count]) . '</div></div>');
      }

      $form['results'] = [
        '#theme' => 'item_list',
        '#items' => $items,
        '#attributes' => ['class' => ['voting-results']],
      ];

      return $form;
    }

    if ($has_voted) {
      // User has voted but results are disabled. Show a thank you message.
      $form['message'] = ['#markup' => $this->t('Thanks for voting.')];
      return $form;
    }

    // Build radio options for voting.
    $option_storage = \Drupal::entityTypeManager()->getStorage('voting_option');
    $ids = $option_storage->getQuery()
      ->condition('question_id', $question->id())
      ->accessCheck(FALSE)
      ->sort('id', 'ASC')
      ->execute();
    $options = $option_storage->loadMultiple($ids);

    $radios = [];
    foreach ($options as $opt) {
      $label = Xss::filterAdmin($opt->label());
      // Append optional description to the label.
      if ($opt->hasField('text') && !$opt->get('text')->isEmpty()) {
        $desc = trim($opt->get('text')->value);
        if ($desc !== '') {
          $label .= '<div class="voting-option-desc">' . Xss::filterAdmin($desc) . '</div>';
        }
      }
      // Add image markup if present.
      if ($opt->hasField('image') && !$opt->get('image')->isEmpty()) {
        $fid = $opt->get('image')->target_id;
        $file = \Drupal::entityTypeManager()->getStorage('file')->load($fid);
        if ($file) {
          $uri = $file->getFileUri();
          $url = \Drupal::service('file_url_generator')->generateAbsoluteString($uri);
          $label = '<img src="' . $url . '" class="voting-option-image" alt="' . Xss::filterAdmin($label) . '"/> ' . $label;
        }
      }
      $radios[$opt->id()] = Markup::create($label);
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
    $qid = $form_state->getValue('question_id');
    $opt = $form_state->getValue('option');
    // Basic validation: ensure option exists and belongs to the provided question.
    if (empty($opt) || empty($qid)) {
      $form_state->setErrorByName('option', $this->t('Please select an option.'));
      return;
    }
    $option_storage = \Drupal::entityTypeManager()->getStorage('voting_option');
    $option = $option_storage->load($opt);
    if (!$option || (int) $option->get('question_id')->target_id !== (int) $qid) {
      $form_state->setErrorByName('option', $this->t('Invalid option selected.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $qid = $form_state->getValue('question_id');
    $opt = $form_state->getValue('option');
    $current_user = \Drupal::currentUser();

    // Prevent double-voting using a uniqueness check (the entity also has a constraint).
    $record_storage = \Drupal::entityTypeManager()->getStorage('voting_record');
    $existing = $record_storage->loadByProperties([
      'question_id' => $qid,
      'uid' => $current_user->id(),
    ]);
    if (!empty($existing)) {
      $this->messenger()->addWarning($this->t('You have already voted on this question.'));
      // Redirect back to the question.
      $form_state->setRedirect('entity.voting_question.canonical', ['voting_question' => $qid]);
      return;
    }

    $record = $record_storage->create([
      'question_id' => $qid,
      'option_id' => $opt,
      'uid' => $current_user->id(),
    ]);
    $record->save();

    // Mark this question as voted in the current session so API calls from
    // the same browser/session will be blocked as well.
    try {
      $request = \Drupal::request();
      $session = $request->getSession();
      if (!$session->isStarted()) {
        $session->start();
      }
      $session_id = $session->getId();
      $kv = \Drupal::keyValue('simple_voting_session_votes');
      $voted = $kv->get($session_id, []);
      $voted[] = (int) $qid;
      $kv->set($session_id, array_values(array_unique($voted)));
    }
    catch (\Exception $e) {
      // Non-fatal: do not block normal flow if session/keyValue fails.
      \Drupal::logger('simple_voting')->warning('Could not mark session vote: @msg', ['@msg' => $e->getMessage()]);
    }

    $this->messenger()->addStatus($this->t('Your vote has been recorded.'));
    $form_state->setRedirect('entity.voting_question.canonical', ['voting_question' => $qid]);
  }

}
