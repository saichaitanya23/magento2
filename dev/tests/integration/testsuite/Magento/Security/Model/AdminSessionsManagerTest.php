<?php

namespace Magento\Security\Model;

/**
 * Class AdminSessionsManagerTest
 * @package Magento\Security\Model
 */
class AdminSessionsManagerTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var \Magento\Backend\Model\Auth
     */
    protected $auth;

    /**
     * @var \Magento\Backend\Model\Auth\Session
     */
    protected $authSession;

    /**
     * @var \Magento\Security\Model\AdminSessionInfo
     */
    protected $adminSessionInfo;

    /**
     * @var \Magento\Security\Model\AdminSessionsManager
     */
    protected $adminSessionsManager;

    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    protected $messageManager;

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * Set up
     */
    protected function setUp()
    {
        parent::setUp();

        $this->objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $this->objectManager->get('Magento\Framework\Config\ScopeInterface')
            ->setCurrentScope(\Magento\Backend\App\Area\FrontNameResolver::AREA_CODE);
        $this->auth = $this->objectManager->create('Magento\Backend\Model\Auth');
        $this->authSession = $this->objectManager->create('Magento\Backend\Model\Auth\Session');
        $this->adminSessionInfo = $this->objectManager->create('Magento\Security\Model\AdminSessionInfo');
        $this->auth->setAuthStorage($this->authSession);
        $this->messageManager = $this->objectManager->get('Magento\Framework\Message\ManagerInterface');
        $this->adminSessionsManager = $this->objectManager->create('Magento\Security\Model\AdminSessionsManager');
    }

    /**
     * Tear down
     */
    protected function tearDown()
    {
        $this->auth = null;
        $this->authSession  = null;
        $this->adminSessionInfo  = null;
        $this->adminSessionsManager = null;
        $this->objectManager = null;
        parent::tearDown();
    }

    /**
     * Test if the admin session is created in database
     */
    public function testIsAdminSessionIsCreated()
    {
        $this->auth->login(
            \Magento\TestFramework\Bootstrap::ADMIN_NAME,
            \Magento\TestFramework\Bootstrap::ADMIN_PASSWORD
        );
        $sessionId = $this->authSession->getSessionId();
        $this->adminSessionInfo->load($sessionId, 'session_id');
        $this->assertGreaterThanOrEqual(1, (int)$this->adminSessionInfo->getId());
        $this->auth->logout();
    }

    /**
     * Test if other sessions are terminated if admin_account_sharing is disabled
     *
     * @magentoAdminConfigFixture admin/security/session_lifetime 100
     * @magentoConfigFixture default_store admin/security/admin_account_sharing 0
     */
    public function testTerminateOtherSessionsProcessLogin()
    {
        $session = $this->objectManager->create('Magento\Security\Model\AdminSessionInfo');
        $session->setSessionId('669e2e3d752e8')
            ->setUserId(1)
            ->setStatus(1)
            ->setCreatedAt(time() - 10)
            ->setUpdatedAt(time() - 9)
            ->save();
        $this->auth->login(
            \Magento\TestFramework\Bootstrap::ADMIN_NAME,
            \Magento\TestFramework\Bootstrap::ADMIN_PASSWORD
        );
        $this->assertContains(
            'All other open sessions for this account were terminated.',
            (string) $this->messageManager->getMessages()->getLastAddedMessage()->getText()
        );
    }

    /**
     * Test if current admin user is logged out
     */
    public function testProcessLogout()
    {
        $this->auth->login(
            \Magento\TestFramework\Bootstrap::ADMIN_NAME,
            \Magento\TestFramework\Bootstrap::ADMIN_PASSWORD
        );
        $sessionId = $this->authSession->getSessionId();
        $this->auth->logout();
        $this->adminSessionInfo->load($sessionId, 'session_id');
        $this->assertEquals($this->adminSessionInfo->getStatus(), AdminSessionInfo::LOGGED_OUT);
    }

    /**
     * Test if current session is retrieved
     */
    public function testGetCurrentSession()
    {
        $this->auth->login(
            \Magento\TestFramework\Bootstrap::ADMIN_NAME,
            \Magento\TestFramework\Bootstrap::ADMIN_PASSWORD
        );
        $sessionId = $this->authSession->getSessionId();
        $this->adminSessionInfo->load($sessionId, 'session_id');
        $this->assertEquals(
            $this->adminSessionInfo->getSessionId(),
            $this->adminSessionsManager->getCurrentSession()->getSessionId()
        );
    }

    /**
     * Test if other sessions were logged out if logoutOtherUserSessions() action was performed
     *
     * @magentoAdminConfigFixture admin/security/session_lifetime 100
     * @magentoConfigFixture default_store admin/security/admin_account_sharing 1
     */
    public function testLogoutOtherUserSessions()
    {
        /** @var \Magento\Security\Model\AdminSessionInfo $session */
        $session = $this->objectManager->create('Magento\Security\Model\AdminSessionInfo');
        $session->setSessionId('669e2e3d752e8')
            ->setUserId(1)
            ->setStatus(1)
            ->setCreatedAt(time() - 50)
            ->setUpdatedAt(time() - 49)
            ->save();
        $this->auth->login(
            \Magento\TestFramework\Bootstrap::ADMIN_NAME,
            \Magento\TestFramework\Bootstrap::ADMIN_PASSWORD
        );
        $collection = $this->getCollectionForLogoutOtherUserSessions($session);
        $this->assertGreaterThanOrEqual(1, $collection->getSize());
        $this->adminSessionsManager->logoutOtherUserSessions();
        $collection = $this->getCollectionForLogoutOtherUserSessions($session);
        $this->assertEquals(0, $collection->getSize());
    }

    /**
     * Collection getter with filters populated for testLogoutOtherUserSessions() method
     *
     * @param AdminSessionInfo $session
     * @return ResourceModel\AdminSessionInfo\Collection
     */
    protected function getCollectionForLogoutOtherUserSessions(\Magento\Security\Model\AdminSessionInfo $session)
    {
        /** @var \Magento\Security\Model\ResourceModel\AdminSessionInfo\Collection $collection */
        $collection = $session->getResourceCollection();
        $collection->filterByUser(
            $this->authSession->getUser()->getId(),
            \Magento\Security\Model\AdminSessionInfo::LOGGED_IN,
            $this->authSession->getSessionId()
        )
            ->filterExpiredSessions(100)
            ->load();

        return $collection;
    }
}
