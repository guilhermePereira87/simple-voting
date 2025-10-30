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
  $questionStorage = $this->entityTypeManager->getStorage('voting_question');
  $optionStorage = $this->entityTypeManager->getStorage('voting_option');
  $fileStorage = $this->entityTypeManager->getStorage('file');

    // Load published questions.
    $ids = $questionStorage->getQuery()
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->sort('id', 'DESC')
      ->execute();

    $questions = [];
    if (!empty($ids)) {
  $entities = $questionStorage->loadMultiple($ids);
      foreach ($entities as $q) {
        /** @var \Drupal\Core\Entity\FieldableEntityInterface $q */
        $qid = $q->id();
        $qItem = [
          'id' => $qid,
          'title' => $q->label(),
          'question_text' => ($q->hasField('question_text') && !$q->get('question_text')->isEmpty()) ? $q->get('question_text')->value : '',
          'show_results' => ($q->hasField('show_results') && !$q->get('show_results')->isEmpty()) ? (bool) $q->get('show_results')->value : FALSE,
        ];

        // For this minimal endpoint we only return the basic question data
        // (id, title and question_text). Options are intentionally not
        // included to keep the response small and simple.
        $qItem['question_text'] = ($q->hasField('question_text') && !$q->get('question_text')->isEmpty()) ? $q->get('question_text')->value : '';
        $questions[] = [
          'id' => $qItem['id'],
          'title' => $qItem['title'],
          'question_text' => $qItem['question_text'],
        ];
      }
    }

    return new JsonResponse(['data' => $questions, 'meta' => ['count' => count($questions)]]);
  }

  /**
   * Return the question JSON especified by ID.
   */
  public function getQuestionById($id) {
  $questionStorage = $this->entityTypeManager->getStorage('voting_question');
  $question = $questionStorage->load($id);
    if (!$question) {
      return new JsonResponse(['error' => 'Question not found'], 404);
    }

    $data = [
      'id' => $question->id(),
      'title' => $question->label(),
      'question_text' => ($question->hasField('question_text') && !$question->get('question_text')->isEmpty()) ? $question->get('question_text')->value : '',
    ];

    // Load options for this question and include them in the response.
    $optionStorage = $this->entityTypeManager->getStorage('voting_option');
    $fileStorage = $this->entityTypeManager->getStorage('file');
    $optIds = $optionStorage->getQuery()
      ->condition('question_id', $question->id())
      ->accessCheck(FALSE)
      ->sort('id', 'ASC')
      ->execute();

    $options = [];
    if (!empty($optIds)) {
      $optEntities = $optionStorage->loadMultiple($optIds);
      foreach ($optEntities as $optEntity) {
        /** @var \Drupal\Core\Entity\FieldableEntityInterface $opt */
        $imageUrl = NULL;
        if ($optEntity->hasField('image') && !$optEntity->get('image')->isEmpty()) {
          $fid = $optEntity->get('image')->target_id;
          if ($fid) {
            $file = $fileStorage->load($fid);
            if ($file) {
              $uri = $file->getFileUri();
              $imageUrl = \Drupal::service('file_url_generator')->generateAbsoluteString($uri);
            }
          }
        }

        $options[] = [
          'id' => $optEntity->id(),
          'title' => $optEntity->label(),
          'description' => ($optEntity->hasField('text') && !$optEntity->get('text')->isEmpty()) ? $optEntity->get('text')->value : NULL,
          'image_url' => $imageUrl,
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
  $questionStorage = $this->entityTypeManager->getStorage('voting_question');
  $question = $questionStorage->load($id);
    if (!$question) {
      return new JsonResponse(['error' => 'Question not found'], 404);
    }

    // Determine whether results are visible for this question.
    $showResults = ($question->hasField('show_results') && !$question->get('show_results')->isEmpty()) ? (bool) $question->get('show_results')->value : FALSE;
    $currentUser = \Drupal::currentUser();
    // Allow access if show_results is true or the current user has an administrative permission.
    if (!$showResults && !$currentUser->hasPermission('administer site configuration')) {
      return new JsonResponse(['error' => 'Results are not available for this question'], 403);
    }

    // Load options and compute counts.
    $optionStorage = $this->entityTypeManager->getStorage('voting_option');
    $optIds = $optionStorage->getQuery()
      ->condition('question_id', $question->id())
      ->accessCheck(FALSE)
      ->sort('id', 'ASC')
      ->execute();

  $options = [];
  $totalVotes = 0;
  $counts = [];
  if (!empty($optIds)) {
      // Use entityQuery to count voting_record per option.
      foreach ($optIds as $optId) {
        $query = \Drupal::entityQuery('voting_record')
          ->accessCheck(FALSE)
          ->condition('question_id', $question->id())
          ->condition('option_id', $optId)
          ->count();
        $count = (int) $query->execute();
        $counts[$optId] = $count;
        $totalVotes += $count;
      }

      $optEntities = $optionStorage->loadMultiple($optIds);
      foreach ($optEntities as $optEntity) {
        $optId = $optEntity->id();
        $options[] = [
          'id' => $optId,
          'title' => $optEntity->label(),
          'count' => $counts[$optId] ?? 0,
        ];
      }
    }

    $data = [
      'id' => $question->id(),
      'title' => $question->label(),
      'total_votes' => $totalVotes,
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
      $currentUser = \Drupal::currentUser();
      // Require authenticated user for API voting. Anonymous votes are not allowed
      // via the API to ensure each vote is attributable to a user.
      if (!$currentUser->isAuthenticated()) {
        return new JsonResponse(['error' => 'Authentication required'], 401);
      }

      $questionStorage = $this->entityTypeManager->getStorage('voting_question');
      $optionStorage = $this->entityTypeManager->getStorage('voting_option');
      $recordStorage = $this->entityTypeManager->getStorage('voting_record');

      // Validate question and option.
  $question = $questionStorage->load($questionId);
      if (!$question) {
        return new JsonResponse(['error' => 'Question not found'], 404);
      }
      // Option must exist and belong to the question.
  $option = $optionStorage->load($optionId);
      if (!$option) {
        return new JsonResponse(['error' => 'Option not found'], 404);
      }
      // Some implementations store question_id as an entity reference field.

      if (method_exists($option, 'hasField') && $option->hasField('question_id')) {
        $optQ = NULL;
        if (method_exists($option, 'get')) {
          $optField = $option->get('question_id');
          if (is_object($optField)) {
            $optQ = $optField->target_id ?? $optField->value ?? NULL;
          }
        }
        if ((string) $optQ !== (string) $questionId) {
          return new JsonResponse(['error' => 'Option does not belong to the question'], 400);
        }
      }

      // Check if the current user (authenticated) already voted.
      if ($currentUser->isAuthenticated()) {
        $uid = $currentUser->id();
        $existing = $recordStorage->loadByProperties([
          'question_id' => $questionId,
          'uid' => $uid,
        ]);
        if (!empty($existing)) {
          return new JsonResponse(['error' => 'User already voted'], 409);
        }

        // Create the voting_record for authenticated user.
        $record = $recordStorage->create([
          'question_id' => $questionId,
          'option_id' => $optionId,
          'uid' => $uid,
        ]);
        $record->save();

        return new JsonResponse(['status' => 'ok', 'vote_id' => $record->id()], 201);
      }

      // At this point the user is authenticated. Enforce single vote per user
      // and create the voting record with the user's uid.
      $uid = $currentUser->id();
      $existing = $recordStorage->loadByProperties([
        'question_id' => $questionId,
        'uid' => $uid,
      ]);
      if (!empty($existing)) {
        return new JsonResponse(['error' => 'User already voted'], 409);
      }

      $record = $recordStorage->create([
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
