<?php

namespace Drupal\og_sm;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\node\NodeTypeInterface;
use Drupal\og\MembershipManagerInterface;
use Drupal\og\OgContextInterface;
use Drupal\og_sm\Event\SiteEvent;
use Drupal\og_sm\Event\SiteEvents;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * A manager to keep track of which nodes are og_sm Site enabled.
 */
class SiteManager implements SiteManagerInterface {

  /**
   * The site type manager.
   *
   * @var \Drupal\og_sm\SiteTypeManagerInterface
   */
  protected $siteTypeManager;

  /**
   * The OG context provider.
   *
   * @var \Drupal\og\OgContextInterface
   */
  protected $ogContext;

  /**
   * The entity storage for node type entities.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $nodeTypeStorage;

  /**
   * The entity storage for node entities.
   *
   * @var \Drupal\node\NodeStorageInterface
   */
  protected $nodeStorage;

  /**
   * The membership manager.
   *
   * @var \Drupal\og\MembershipManagerInterface
   */
  protected $membershipManager;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The service that contains the current active user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $accountProxy;

  /**
   * Constructs a SiteManager object.
   *
   * @param \Drupal\og_sm\SiteTypeManagerInterface $siteTypeManager
   *   The entity type manager.
   * @param \Drupal\og\OgContextInterface $ogContext
   *   The OG context provider.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\og\MembershipManagerInterface $membershipManager
   *   The membership manager.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\Session\AccountProxyInterface $accountProxy
   *   The service that contains the current active user.
   */
  public function __construct(SiteTypeManagerInterface $siteTypeManager, OgContextInterface $ogContext, EntityTypeManagerInterface $entityTypeManager, MembershipManagerInterface $membershipManager, EventDispatcherInterface $eventDispatcher, ModuleHandlerInterface $moduleHandler, AccountProxyInterface $accountProxy) {
    $this->siteTypeManager = $siteTypeManager;
    $this->ogContext = $ogContext;
    $this->nodeTypeStorage = $entityTypeManager->getStorage('node_type');
    $this->nodeStorage = $entityTypeManager->getStorage('node');
    $this->membershipManager = $membershipManager;
    $this->eventDispatcher = $eventDispatcher;
    $this->moduleHandler = $moduleHandler;
    $this->accountProxy = $accountProxy;
  }

  /**
   * {@inheritdoc}
   */
  public function isSite(NodeInterface $node) {
    $type = $this->nodeTypeStorage->load($node->getType());
    return $this->siteTypeManager->isSiteType($type);
  }

  /**
   * {@inheritdoc}
   */
  public function currentSite() {
    $entity = $this->ogContext->getGroup();
    if (!$entity || !$entity instanceof NodeTypeInterface || $this->isSite($entity)) {
      return NULL;
    }

    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function load($id) {
    $site = $this->nodeStorage->load($id);
    if (!$site || !$this->isSite($site)) {
      return FALSE;
    }
    return $site;
  }

  /**
   * {@inheritdoc}
   */
  public function getSiteHomePage(NodeInterface $site = NULL) {
    // Fallback to current Site.
    if (!$site) {
      $site = $this->currentSite();
    }

    // Only if there is a Site.
    if (!$site || !$this->isSite($site)) {
      return FALSE;
    }

    // This can be called multiple times, lets add some static caching.
    $urls = &drupal_static(__FUNCTION__, array());
    if (isset($urls[$site->id()])) {
      return $urls[$site->id()];
    }

    $routeName = 'entity.node.canonical';
    $routeParameters = ['node' => $site->id()];
    $this->moduleHandler->alter('og_sm_site_homepage', $site, $routeName, $routeParameters);
    $urls[$site->id()] = Url::fromRoute($routeName, $routeParameters);
    return $urls[$site->id()];
  }

  /**
   * {@inheritdoc}
   */
  public function clearSiteCache(NodeInterface $site) {
    $this->eventDispatch(SiteEvents::CACHE_CLEAR, $site);
  }

  /**
   * {@inheritdoc}
   */
  public function eventDispatch($action, NodeInterface $node) {
    // Only for Site node types.
    if (!$this->isSite($node)) {
      return;
    }

    $event = new SiteEvent($node);
    $this->eventDispatcher->dispatch($action, $event);

    // Dispatch the save event for insert/update operations.
    $actions = [SiteEvents::INSERT, SiteEvents::UPDATE];
    if (in_array($action, $actions)) {
      $this->eventDispatch(SiteEvents::SAVE, $node);
    }

    // Register shutdown functions for post_op operations.
    $post_actions = [
      SiteEvents::INSERT => SiteEvents::POST_INSERT,
      SiteEvents::UPDATE => SiteEvents::POST_UPDATE,
      SiteEvents::SAVE => SiteEvents::POST_SAVE,
      SiteEvents::DELETE => SiteEvents::POST_DELETE,
    ];
    if (isset($post_actions[$action])) {
      drupal_register_shutdown_function(
        [$this, 'eventDispatch'],
        $post_actions[$action],
        $node
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getAllSiteNodeIds() {
    $siteTypes = $this->siteTypeManager->getSiteTypes();
    if (!$siteTypes) {
      return [];
    }

    $query = $this->nodeStorage->getQuery()->condition('type', array_keys($siteTypes), 'IN');
    return $query->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function getAllSites() {
    $ids = $this->getAllSiteNodeIds();
    if (!$ids) {
      return [];
    }
    return $this->nodeStorage->loadMultiple($ids);
  }

  /**
   * Helper function to get a list of Site objects from a list of group id's.
   *
   * @param \Drupal\Core\Entity\EntityInterface[][] $groups
   *   An associative array, keyed by group entity type, each item an array of
   *   group entities.
   *
   * @return \Drupal\node\NodeInterface[]
   *   All Site nodes keyed by their nid.
   */
  protected function filterSitesFromGroups(array $groups) {
    $sites = [];
    if (!isset($groups['node'])) {
      return $sites;
    }

    /* @var \Drupal\node\NodeInterface $group */
    foreach ($groups['node'] as $group) {
      if ($this->isSite($group)) {
        $sites[$group->id()] = $group;
      }
    }

    return $sites;
  }

  /**
   * {@inheritdoc}
   */
  public function getSitesFromContent(NodeInterface $node) {
    $groups = $this->membershipManager->getGroups($node);
    return $this->filterSitesFromGroups($groups);
  }

  /**
   * {@inheritdoc}
   */
  public function getSiteFromContent(NodeInterface $node) {
    $sites = $this->getSitesFromContent($node);

    if (empty($sites)) {
      return FALSE;
    }
    return reset($sites);
  }

  /**
   * {@inheritdoc}
   */
  public function isSiteContent(NodeInterface $node) {
    return (bool) $this->getSiteFromContent($node);
  }

  /**
   * {@inheritdoc}
   */
  public function contentIsSiteMember(NodeInterface $node, NodeInterface $site) {
    $sites = $this->getSiteFromContent($node);
    return !empty($sites[$site->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getUserSites(AccountInterface $account) {
    $groups = $this->membershipManager->getUserGroups($account);
    return $this->filterSitesFromGroups($groups);
  }

  /**
   * {@inheritdoc}
   */
  public function getUserManageableSites(AccountInterface $account = NULL) {
    if (!$account) {
      $account = $this->accountProxy->getAccount();
    }
    return $this->getUserSites($account);
  }

  /**
   * {@inheritdoc}
   */
  public function userIsMemberOfSite(AccountInterface $account, NodeInterface $site) {
    $sites = $this->getUserSites($account);
    return !empty($sites[$site->id()]);
  }

}
