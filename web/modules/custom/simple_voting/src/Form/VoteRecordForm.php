<?php

namespace Drupal\simple_voting\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\simple_voting\Entity\VotingQuestion;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Render\Markup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to submit a vote and create a VotingRecord entity.
 */
class VoteRecordForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * File URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * KeyValue factory.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueFactoryInterface
   */
  protected $keyValueFactory;

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
    $instance->currentUser = $container->get('current_user');
    $instance->database = $container->get('database');
    $instance->fileUrlGenerator = $container->get('file_url_generator');
    $instance->requestStack = $container->get('request_stack');
    $instance->keyValueFactory = $container->get('keyvalue');
    $instance->logger = $container->get('logger.factory')->get('simple_voting');
    return $instance;
  }

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
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param \Drupal\simple_voting\Entity\VotingQuestion|null $question
   *   The question entity passed from the view builder.
   *
   * @return array $form
   *   The built form array.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?VotingQuestion $question = NULL) {
    if (!$question) {
      return ['#markup' => $this->t('No question provided.')];
    }

    $currentUser = $this->currentUser;

    // Ensure only authenticated users can vote.
    if (!$currentUser->isAuthenticated()) {
      $form['login_notice'] = [
        '#markup' => $this->t('You must be logged in to vote.'),
        '#weight' => -10,
      ];
      return $form;
    }

    // Check if the user already voted.
    $recordStorage = $this->entityTypeManager->getStorage('voting_record');
    $existing = $recordStorage->loadByProperties([
      'question_id' => $question->id(),
      'uid' => $currentUser->id(),
    ]);
    $hasVoted = !empty($existing);

    // If user has voted and results should be shown, display results and stop.
    if ($hasVoted && $question->hasField('show_results') && $question->get('show_results')->value) {
      // Count votes grouped by option.
      $db = $this->database;
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
      $optionStorage = $this->entityTypeManager->getStorage('voting_option');
      $optIds = $optionStorage->getQuery()
        ->condition('question_id', $question->id())
        ->accessCheck(FALSE)
        ->sort('id', 'ASC')
        ->execute();
      $options = $optionStorage->loadMultiple($optIds);
      foreach ($options as $optEntity) {
        $title = Xss::filterAdmin($optEntity->label());
        $count = $counts[$optEntity->id()] ?? 0;
        $items[] = Markup::create(
          '<div class="voting-result">' .
          '<div class="voting-result-title">' . $title . '</div>' .
          '<div class="voting-result-count">' . $this->t('@count votes', ['@count' => $count]) . '</div>' .
          '</div>'
        );
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
    $optionStorage = $this->entityTypeManager->getStorage('voting_option');
    $optIds = $optionStorage->getQuery()
      ->condition('question_id', $question->id())
      ->accessCheck(FALSE)
      ->sort('id', 'ASC')
      ->execute();
    $options = $optionStorage->loadMultiple($optIds);

    $radios = [];
    foreach ($options as $optEntity) {
      $label = Xss::filterAdmin($optEntity->label());
      // Append optional description to the label.
      if ($optEntity->hasField('text') && !$optEntity->get('text')->isEmpty()) {
        $desc = trim($optEntity->get('text')->value);
        if ($desc !== '') {
          $label .= '<div class="voting-option-desc">' . Xss::filterAdmin($desc) . '</div>';
        }
      }
      // Add image markup if present.
      if ($optEntity->hasField('image') && !$optEntity->get('image')->isEmpty()) {
        $fid = $optEntity->get('image')->target_id;
        $file = $this->entityTypeManager->getStorage('file')->load($fid);
        if ($file) {
          $uri = $file->getFileUri();
          $url = $this->fileUrlGenerator->generateAbsoluteString($uri);
          $label = '<img src="' . $url . '" class="voting-option-image" alt="' . Xss::filterAdmin($label) . '"/> '
            . $label;
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
    // Basic validation: ensure option exists and belongs to the question.
    if (empty($optionVal) || empty($questionId)) {
      $form_state->setErrorByName('option', $this->t('Please select an option.'));
      return;
    }
    $optionStorage = $this->entityTypeManager->getStorage('voting_option');
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
    $currentUser = $this->currentUser;

    // Prevent double-voting; the entity also defines a uniqueness constraint.
    $recordStorage = $this->entityTypeManager->getStorage('voting_record');
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

    // Mark this question as voted in the current session so API calls from
    // the same browser/session will be blocked as well.
    try {
      $request = $this->requestStack->getCurrentRequest();
      $session = $request->getSession();
      if (!$session->isStarted()) {
        $session->start();
      }
      $session_id = $session->getId();
      $kv = $this->keyValueFactory->get('simple_voting_session_votes');
      $voted = $kv->get($session_id, []);
      $voted[] = (int) $questionId;
      $kv->set($session_id, array_values(array_unique($voted)));
    }
    catch (\Exception $e) {
      // Non-fatal: do not block normal flow if session/keyValue fails.
      $this->logger->warning('Could not mark session vote: @msg', ['@msg' => $e->getMessage()]);
    }

    $this->messenger()->addStatus($this->t('Your vote has been recorded.'));
    $form_state->setRedirect('entity.voting_question.canonical', ['voting_question' => $questionId]);
  }

}
