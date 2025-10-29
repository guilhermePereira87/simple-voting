<?php

namespace Drupal\simple_voting\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\user\UserInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Simple API controller for the simple_voting module.
 */
class ApiController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * ApiController constructor.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Return a JSON list of published voting questions and their options.
   */
  public function questions() {
    $question_storage = $this->entityTypeManager->getStorage('voting_question');
    $option_storage = $this->entityTypeManager->getStorage('voting_option');
    $file_storage = $this->entityTypeManager->getStorage('file');

    // Load published questions.
    $ids = $question_storage->getQuery()
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->sort('id', 'DESC')
      ->execute();

    $questions = [];
    if (!empty($ids)) {
      $entities = $question_storage->loadMultiple($ids);
      foreach ($entities as $q) {
        /** @var \Drupal\Core\Entity\FieldableEntityInterface $q */
        $qid = $q->id();
        $q_item = [
          'id' => $qid,
          'title' => $q->label(),
          'question_text' => ($q->hasField('question_text') && !$q->get('question_text')->isEmpty()) ? $q->get('question_text')->value : '',
          'show_results' => ($q->hasField('show_results') && !$q->get('show_results')->isEmpty()) ? (bool) $q->get('show_results')->value : FALSE,
        ];

        // For this minimal endpoint we only return the basic question data
        // (id, title and question_text). Options are intentionally not
        // included to keep the response small and simple.
        $q_item['question_text'] = ($q->hasField('question_text') && !$q->get('question_text')->isEmpty()) ? $q->get('question_text')->value : '';
        $questions[] = [
          'id' => $q_item['id'],
          'title' => $q_item['title'],
          'question_text' => $q_item['question_text'],
        ];
      }
    }

    return new JsonResponse(['data' => $questions, 'meta' => ['count' => count($questions)]]);
  }

  /**
   * Return the question JSON especified by ID.
   */
  public function getQuestionById($id) {
    $question_storage = $this->entityTypeManager()->getStorage('voting_question');
    $question = $question_storage->load($id);
    if (!$question) {
      return new JsonResponse(['error' => 'Question not found'], 404);
    }

    $data = [
      'id' => $question->id(),
      'title' => $question->label(),
      'question_text' => ($question->hasField('question_text') && !$question->get('question_text')->isEmpty()) ? $question->get('question_text')->value : '',
    ];

    // Load options for this question and include them in the response.
    $option_storage = $this->entityTypeManager->getStorage('voting_option');
    $file_storage = $this->entityTypeManager->getStorage('file');
    $opt_ids = $option_storage->getQuery()
      ->condition('question_id', $question->id())
      ->accessCheck(FALSE)
      ->sort('id', 'ASC')
      ->execute();

    $options = [];
    if (!empty($opt_ids)) {
      $opt_entities = $option_storage->loadMultiple($opt_ids);
      foreach ($opt_entities as $opt) {
        /** @var \Drupal\Core\Entity\FieldableEntityInterface $opt */
        $image_url = NULL;
        if ($opt->hasField('image') && !$opt->get('image')->isEmpty()) {
          $fid = $opt->get('image')->target_id;
          if ($fid) {
            $file = $file_storage->load($fid);
            if ($file) {
              $uri = $file->getFileUri();
              $image_url = \Drupal::service('file_url_generator')->generateAbsoluteString($uri);
            }
          }
        }

        $options[] = [
          'id' => $opt->id(),
          'title' => $opt->label(),
          'description' => ($opt->hasField('text') && !$opt->get('text')->isEmpty()) ? $opt->get('text')->value : NULL,
          'image_url' => $image_url,
        ];
      }
    }

    $data['options'] = $options;

    return new JsonResponse(['data' => $data]);
  }

  /**
   * Return voting results for a question if allowed.
   *
   * GET /api/v1/questions/{id}/results
   */
  public function getQuestionResults($id) {
    $question_storage = $this->entityTypeManager->getStorage('voting_question');
    $question = $question_storage->load($id);
    if (!$question) {
      return new JsonResponse(['error' => 'Question not found'], 404);
    }

    // Determine whether results are visible for this question.
    $show_results = ($question->hasField('show_results') && !$question->get('show_results')->isEmpty()) ? (bool) $question->get('show_results')->value : FALSE;
    $current_user = \Drupal::currentUser();
    // Allow access if show_results is true or the current user has an administrative permission.
    if (!$show_results && !$current_user->hasPermission('administer site configuration')) {
      return new JsonResponse(['error' => 'Results are not available for this question'], 403);
    }

    // Load options and compute counts.
    $option_storage = $this->entityTypeManager->getStorage('voting_option');
    $opt_ids = $option_storage->getQuery()
      ->condition('question_id', $question->id())
      ->accessCheck(FALSE)
      ->sort('id', 'ASC')
      ->execute();

    $options = [];
    $total_votes = 0;
    $counts = [];
    if (!empty($opt_ids)) {
      // Use entityQuery to count voting_record per option.
      foreach ($opt_ids as $opt_id) {
        $query = \Drupal::entityQuery('voting_record')
          ->accessCheck(FALSE)
          ->condition('question_id', $question->id())
          ->condition('option_id', $opt_id)
          ->count();
        $count = (int) $query->execute();
        $counts[$opt_id] = $count;
        $total_votes += $count;
      }

      $opt_entities = $option_storage->loadMultiple($opt_ids);
      foreach ($opt_entities as $opt) {
        $opt_id = $opt->id();
        $options[] = [
          'id' => $opt_id,
          'title' => $opt->label(),
          'count' => $counts[$opt_id] ?? 0,
        ];
      }
    }

    $data = [
      'id' => $question->id(),
      'title' => $question->label(),
      'total_votes' => $total_votes,
      'options' => $options,
    ];

    return new JsonResponse(['data' => $data]);
  }

  /**
   * Authenticate a user and start a session.
   *
   * Expects JSON body: { "name": "username", "pass": "password" }
   * Returns 200 on success and sets the session cookie. Returns 401 on failure.
   */
  public function login(Request $request) {
    // Accept JSON or form-encoded bodies.
    $data = [];
    $content = $request->getContent();
    if (!empty($content)) {
      $decoded = json_decode($content, TRUE);
      if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $data = $decoded;
      }
    }
    // Fallback to request parameters if not JSON.
    if (empty($data)) {
      $data['name'] = $request->request->get('name');
      $data['pass'] = $request->request->get('pass');
    }

    $name = $data['name'] ?? NULL;
    $pass = $data['pass'] ?? NULL;
    if (empty($name) || empty($pass)) {
      return new JsonResponse(['error' => 'Missing credentials'], 400);
    }

    try {
      $auth = \Drupal::service('user.auth');
      $uid = $auth->authenticate($name, $pass);
      if ($uid === FALSE || $uid === 0) {
        return new JsonResponse(['error' => 'Invalid credentials'], 401);
      }

      // Load user entity and finalize login (sets session, cookies, etc.).
      $account = \Drupal\user\Entity\User::load($uid);
      if (!$account instanceof UserInterface) {
        return new JsonResponse(['error' => 'Invalid user account'], 401);
      }

      // Finalize login: sets current user in session and sends cookie in response.
      user_login_finalize($account);

      return new JsonResponse(['status' => 'ok', 'uid' => $uid]);
    }
    catch (\Exception $e) {
      \Drupal::logger('simple_voting')->error('Login error: @msg', ['@msg' => $e->getMessage()]);
      return new JsonResponse(['error' => 'Internal server error'], 500);
    }
  }

  /**
   * Vote in a question when applicable.
   */
  public function vote ($questionId, $optionId) {
    try {
      $current_user = \Drupal::currentUser();
      // Require authenticated user for API voting. Anonymous votes are not allowed
      // via the API to ensure each vote is attributable to a user.
      if (!$current_user->isAuthenticated()) {
        return new JsonResponse(['error' => 'Authentication required'], 401);
      }

      $question_storage = $this->entityTypeManager->getStorage('voting_question');
      $option_storage = $this->entityTypeManager->getStorage('voting_option');
      $record_storage = $this->entityTypeManager->getStorage('voting_record');

      // Validate question and option.
      $question = $question_storage->load($questionId);
      if (!$question) {
        return new JsonResponse(['error' => 'Question not found'], 404);
      }
      // Option must exist and belong to the question.
      $option = $option_storage->load($optionId);
      if (!$option) {
        return new JsonResponse(['error' => 'Option not found'], 404);
      }
      // Some implementations store question_id as an entity reference field.
      if (method_exists($option, 'hasField') && $option->hasField('question_id')) {
        $opt_q = NULL;
        if (method_exists($option, 'get')) {
          $opt_field = $option->get('question_id');
          if (is_object($opt_field)) {
            $opt_q = $opt_field->target_id ?? $opt_field->value ?? NULL;
          }
        }
        if ((string) $opt_q !== (string) $questionId) {
          return new JsonResponse(['error' => 'Option does not belong to the question'], 400);
        }
      }

      // Check if the current user (authenticated) already voted.
      if ($current_user->isAuthenticated()) {
        $uid = $current_user->id();
        $existing = $record_storage->loadByProperties([
          'question_id' => $questionId,
          'uid' => $uid,
        ]);
        if (!empty($existing)) {
          return new JsonResponse(['error' => 'User already voted'], 409);
        }

        // Create the voting_record for authenticated user.
        $record = $record_storage->create([
          'question_id' => $questionId,
          'option_id' => $optionId,
          'uid' => $uid,
        ]);
        $record->save();

        return new JsonResponse(['status' => 'ok', 'vote_id' => $record->id()], 201);
      }

      // At this point the user is authenticated. Enforce single vote per user
      // and create the voting record with the user's uid.
      $uid = $current_user->id();
      $existing = $record_storage->loadByProperties([
        'question_id' => $questionId,
        'uid' => $uid,
      ]);
      if (!empty($existing)) {
        return new JsonResponse(['error' => 'User already voted'], 409);
      }

      $record = $record_storage->create([
        'question_id' => $questionId,
        'option_id' => $optionId,
        'uid' => $uid,
      ]);
      $record->save();

      return new JsonResponse(['status' => 'ok', 'vote_id' => $record->id()], 201);
    }
    catch (\Exception $e) {
      // Detect common duplicate/constraint DB error (SQLSTATE 23000) and map to 409.
      $message = $e->getMessage();
      $code = $e->getCode();
      if (strpos($message, 'Duplicate') !== FALSE || (is_string($code) && strpos($code, '23000') !== FALSE) || $code === 23000) {
        return new JsonResponse(['error' => 'Already voted'], 409);
      }
      \Drupal::logger('simple_voting')->error('Unexpected error in API vote: @msg', ['@msg' => $message]);
      return new JsonResponse(['error' => 'Internal server error'], 500);
    }
  }

}
