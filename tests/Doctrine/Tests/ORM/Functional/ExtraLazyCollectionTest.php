<?php

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\ORM\Mapping\ClassMetadataInfo;

require_once __DIR__ . '/../../TestInit.php';

/**
 * Description of ExtraLazyCollectionTest
 *
 * @author beberlei
 */
class ExtraLazyCollectionTest extends \Doctrine\Tests\OrmFunctionalTestCase
{
    private $userId;
    private $groupId;
    private $articleId;

    private $topic;
    private $phonenumber;

    public function setUp()
    {
        $this->useModelSet('cms');
        parent::setUp();

        $class = $this->_em->getClassMetadata('Doctrine\Tests\Models\CMS\CmsUser');
        $class->associationMappings['groups']['fetch'] = ClassMetadataInfo::FETCH_EXTRA_LAZY;
        $class->associationMappings['groups']['indexBy'] = 'name';
        $class->associationMappings['articles']['fetch'] = ClassMetadataInfo::FETCH_EXTRA_LAZY;
        $class->associationMappings['articles']['indexBy'] = 'topic';
        $class->associationMappings['phonenumbers']['fetch'] = ClassMetadataInfo::FETCH_EXTRA_LAZY;
        $class->associationMappings['phonenumbers']['indexBy'] = 'phonenumber';

        $class = $this->_em->getClassMetadata('Doctrine\Tests\Models\CMS\CmsGroup');
        $class->associationMappings['users']['fetch'] = ClassMetadataInfo::FETCH_EXTRA_LAZY;

        $this->loadFixture();
    }

    public function tearDown()
    {
        parent::tearDown();

        $class = $this->_em->getClassMetadata('Doctrine\Tests\Models\CMS\CmsUser');
        $class->associationMappings['groups']['fetch'] = ClassMetadataInfo::FETCH_LAZY;
        $class->associationMappings['articles']['fetch'] = ClassMetadataInfo::FETCH_LAZY;
        $class->associationMappings['phonenumbers']['fetch'] = ClassMetadataInfo::FETCH_LAZY;

        unset($class->associationMappings['groups']['indexBy']);
        unset($class->associationMappings['articles']['indexBy']);
        unset($class->associationMappings['phonenumbers']['indexBy']);

        $class = $this->_em->getClassMetadata('Doctrine\Tests\Models\CMS\CmsGroup');
        $class->associationMappings['users']['fetch'] = ClassMetadataInfo::FETCH_LAZY;
    }

    /**
     * @group DDC-546
     */
    public function testCountNotInitializesCollection()
    {
        $user = $this->_em->find('Doctrine\Tests\Models\CMS\CmsUser', $this->userId);
        $queryCount = $this->getCurrentQueryCount();

        $this->assertFalse($user->groups->isInitialized());
        $this->assertEquals(3, count($user->groups));
        $this->assertFalse($user->groups->isInitialized());

        foreach ($user->groups AS $group) { }

        $this->assertEquals($queryCount + 2, $this->getCurrentQueryCount(), "Expecting two queries to be fired for count, then iteration.");
    }

    /**
     * @group DDC-546
     */
    public function testCountWhenNewEntityPresent()
    {
        $user = $this->_em->find('Doctrine\Tests\Models\CMS\CmsUser', $this->userId);

        $newGroup = new \Doctrine\Tests\Models\CMS\CmsGroup();
        $newGroup->name = "Test4";

        $user->addGroup($newGroup);
        $this->_em->persist($newGroup);

        $this->assertFalse($user->groups->isInitialized());
        $this->assertEquals(4, count($user->groups));
        $this->assertFalse($user->groups->isInitialized());
    }

    /**
     * @group DDC-546
     */
    public function testCountWhenInitialized()
    {
        $user = $this->_em->find('Doctrine\Tests\Models\CMS\CmsUser', $this->userId);
        $queryCount = $this->getCurrentQueryCount();

        foreach ($user->groups AS $group) { }

        $this->assertTrue($user->groups->isInitialized());
        $this->assertEquals(3, count($user->groups));
        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount(), "Should only execute one query to initialize collection, no extra query for count() more.");
    }

    /**
     * @group DDC-546
     */
    public function testCountInverseCollection()
    {
        $group = $this->_em->find('Doctrine\Tests\Models\CMS\CmsGroup', $this->groupId);
        $this->assertFalse($group->users->isInitialized(), "Pre-Condition");

        $this->assertEquals(4, count($group->users));
        $this->assertFalse($group->users->isInitialized(), "Extra Lazy collection should not be initialized by counting the collection.");
    }

    /**
     * @group DDC-546
     */
    public function testCountOneToMany()
    {
        $user = $this->_em->find('Doctrine\Tests\Models\CMS\CmsUser', $this->userId);
        $this->assertFalse($user->groups->isInitialized(), "Pre-Condition");

        $this->assertEquals(2, count($user->articles));
    }

    /**
     * @group DDC-546
     */
    public function testFullSlice()
    {
        $user = $this->_em->find('Doctrine\Tests\Models\CMS\CmsUser', $this->userId);
        $this->assertFalse($user->groups->isInitialized(), "Pre-Condition: Collection is not initialized.");

        $someGroups = $user->groups->slice(null);
        $this->assertEquals(3, count($someGroups));
    }

    /**
     * @group DDC-546
     */
    public function testSlice()
    {
        $user = $this->_em->find('Doctrine\Tests\Models\CMS\CmsUser', $this->userId);
        $this->assertFalse($user->groups->isInitialized(), "Pre-Condition: Collection is not initialized.");

        $queryCount = $this->getCurrentQueryCount();

        $someGroups = $user->groups->slice(0, 2);

        $this->assertContainsOnly('Doctrine\Tests\Models\CMS\CmsGroup', $someGroups);
        $this->assertEquals(2, count($someGroups));
        $this->assertFalse($user->groups->isInitialized(), "Slice should not initialize the collection if it wasn't before!");

        $otherGroup = $user->groups->slice(2, 1);

        $this->assertContainsOnly('Doctrine\Tests\Models\CMS\CmsGroup', $otherGroup);
        $this->assertEquals(1, count($otherGroup));
        $this->assertFalse($user->groups->isInitialized());

        foreach ($user->groups AS $group) { }

        $this->assertTrue($user->groups->isInitialized());
        $this->assertEquals(3, count($user->groups));

        $this->assertEquals($queryCount + 3, $this->getCurrentQueryCount());
    }

    /**
     * @group DDC-546
     */
    public function testSliceInitializedCollection()
    {
        $user = $this->_em->find('Doctrine\Tests\Models\CMS\CmsUser', $this->userId);
        $queryCount = $this->getCurrentQueryCount();

        foreach ($user->groups AS $group) { }

        $someGroups = $user->groups->slice(0, 2);

        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount());

        $this->assertEquals(2, count($someGroups));
        $this->assertTrue($user->groups->contains(array_shift($someGroups)));
        $this->assertTrue($user->groups->contains(array_shift($someGroups)));
    }

    /**
     * @group DDC-546
     */
    public function testSliceInverseCollection()
    {
        $group = $this->_em->find('Doctrine\Tests\Models\CMS\CmsGroup', $this->groupId);
        $this->assertFalse($group->users->isInitialized(), "Pre-Condition");
        $queryCount = $this->getCurrentQueryCount();

        $someUsers = $group->users->slice(0, 2);
        $otherUsers = $group->users->slice(2, 2);

        $this->assertContainsOnly('Doctrine\Tests\Models\CMS\CmsUser', $someUsers);
        $this->assertContainsOnly('Doctrine\Tests\Models\CMS\CmsUser', $otherUsers);
        $this->assertEquals(2, count($someUsers));
        $this->assertEquals(2, count($otherUsers));

        // +2 queries executed by slice
        $this->assertEquals($queryCount + 2, $this->getCurrentQueryCount(), "Slicing two parts should only execute two additional queries.");
    }

    /**
     * @group DDC-546
     */
    public function testSliceOneToMany()
    {
        $user = $this->_em->find('Doctrine\Tests\Models\CMS\CmsUser', $this->userId);
        $this->assertFalse($user->articles->isInitialized(), "Pre-Condition: Collection is not initialized.");

        $queryCount = $this->getCurrentQueryCount();

        $someArticle = $user->articles->slice(0, 1);
        $otherArticle = $user->articles->slice(1, 1);

        $this->assertEquals($queryCount + 2, $this->getCurrentQueryCount());
    }

    /**
     * @group DDC-546
     */
    public function testContainsOneToMany()
    {
        $user = $this->_em->find('Doctrine\Tests\Models\CMS\CmsUser', $this->userId);
        $this->assertFalse($user->articles->isInitialized(), "Pre-Condition: Collection is not initialized.");

        // Test One to Many existence retrieved from DB
        $article    = $this->_em->find('Doctrine\Tests\Models\CMS\CmsArticle', $this->articleId);
        $queryCount = $this->getCurrentQueryCount();

        $this->assertTrue($user->articles->contains($article));
        $this->assertFalse($user->articles->isInitialized(), "Post-Condition: Collection is not initialized.");
        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount());

        // Test One to Many existence with state new
        $article = new \Doctrine\Tests\Models\CMS\CmsArticle();
        $article->topic = "Testnew";
        $article->text = "blub";

        $queryCount = $this->getCurrentQueryCount();
        $this->assertFalse($user->articles->contains($article));
        $this->assertEquals($queryCount, $this->getCurrentQueryCount(), "Checking for contains of new entity should cause no query to be executed.");

        // Test One to Many existence with state clear
        $this->_em->persist($article);
        $this->_em->flush();

        $queryCount = $this->getCurrentQueryCount();
        $this->assertFalse($user->articles->contains($article));
        $this->assertEquals($queryCount+1, $this->getCurrentQueryCount(), "Checking for contains of persisted entity should cause one query to be executed.");
        $this->assertFalse($user->articles->isInitialized(), "Post-Condition: Collection is not initialized.");

        // Test One to Many existence with state managed
        $article = new \Doctrine\Tests\Models\CMS\CmsArticle();
        $article->topic = "How to not fail anymore on tests";
        $article->text = "That is simple! Just write more tests!";

        $this->_em->persist($article);

        $queryCount = $this->getCurrentQueryCount();

        $this->assertFalse($user->articles->contains($article));
        $this->assertEquals($queryCount, $this->getCurrentQueryCount(), "Checking for contains of managed entity (but not persisted) should cause no query to be executed.");
        $this->assertFalse($user->articles->isInitialized(), "Post-Condition: Collection is not initialized.");
    }

    /**
     * @group DDC-546
     */
    public function testContainsManyToMany()
    {
        $user = $this->_em->find('Doctrine\Tests\Models\CMS\CmsUser', $this->userId);
        $this->assertFalse($user->groups->isInitialized(), "Pre-Condition: Collection is not initialized.");

        // Test Many to Many existence retrieved from DB
        $group      = $this->_em->find('Doctrine\Tests\Models\CMS\CmsGroup', $this->groupId);
        $queryCount = $this->getCurrentQueryCount();

        $this->assertTrue($user->groups->contains($group));
        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount(), "Checking for contains of managed entity should cause one query to be executed.");
        $this->assertFalse($user->groups->isInitialized(), "Post-Condition: Collection is not initialized.");

        // Test Many to Many existence with state new
        $group = new \Doctrine\Tests\Models\CMS\CmsGroup();
        $group->name = "A New group!";

        $queryCount = $this->getCurrentQueryCount();

        $this->assertFalse($user->groups->contains($group));
        $this->assertEquals($queryCount, $this->getCurrentQueryCount(), "Checking for contains of new entity should cause no query to be executed.");
        $this->assertFalse($user->groups->isInitialized(), "Post-Condition: Collection is not initialized.");

        // Test Many to Many existence with state clear
        $this->_em->persist($group);
        $this->_em->flush();

        $queryCount = $this->getCurrentQueryCount();

        $this->assertFalse($user->groups->contains($group));
        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount(), "Checking for contains of persisted entity should cause one query to be executed.");
        $this->assertFalse($user->groups->isInitialized(), "Post-Condition: Collection is not initialized.");

        // Test Many to Many existence with state managed
        $group = new \Doctrine\Tests\Models\CMS\CmsGroup();
        $group->name = "My managed group";

        $this->_em->persist($group);

        $queryCount = $this->getCurrentQueryCount();

        $this->assertFalse($user->groups->contains($group));
        $this->assertEquals($queryCount, $this->getCurrentQueryCount(), "Checking for contains of managed entity (but not persisted) should cause no query to be executed.");
        $this->assertFalse($user->groups->isInitialized(), "Post-Condition: Collection is not initialized.");
    }

    /**
     * @group DDC-546
     */
    public function testContainsManyToManyInverse()
    {
        $group = $this->_em->find('Doctrine\Tests\Models\CMS\CmsGroup', $this->groupId);
        $this->assertFalse($group->users->isInitialized(), "Pre-Condition: Collection is not initialized.");

        $user = $this->_em->find('Doctrine\Tests\Models\CMS\CmsUser', $this->userId);

        $queryCount = $this->getCurrentQueryCount();
        $this->assertTrue($group->users->contains($user));
        $this->assertEquals($queryCount+1, $this->getCurrentQueryCount(), "Checking for contains of managed entity should cause one query to be executed.");
        $this->assertFalse($user->groups->isInitialized(), "Post-Condition: Collection is not initialized.");

        $newUser = new \Doctrine\Tests\Models\CMS\CmsUser();
        $newUser->name = "A New group!";

        $queryCount = $this->getCurrentQueryCount();
        $this->assertFalse($group->users->contains($newUser));
        $this->assertEquals($queryCount, $this->getCurrentQueryCount(), "Checking for contains of new entity should cause no query to be executed.");
        $this->assertFalse($user->groups->isInitialized(), "Post-Condition: Collection is not initialized.");
    }

    /**
     *
     */
    public function testRemoveElementOneToMany()
    {
        $user = $this->_em->find('Doctrine\Tests\Models\CMS\CmsUser', $this->userId);
        $this->assertFalse($user->articles->isInitialized(), "Pre-Condition: Collection is not initialized.");

        // Test One to Many removal with Entity retrieved from DB
        $article    = $this->_em->find('Doctrine\Tests\Models\CMS\CmsArticle', $this->articleId);
        $queryCount = $this->getCurrentQueryCount();

        $user->articles->removeElement($article);

        $this->assertFalse($user->articles->isInitialized(), "Post-Condition: Collection is not initialized.");
        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount());

        // Test One to Many removal with Entity state as new
        $article = new \Doctrine\Tests\Models\CMS\CmsArticle();
        $article->topic = "Testnew";
        $article->text = "blub";

        $queryCount = $this->getCurrentQueryCount();

        $user->articles->removeElement($article);

        $this->assertEquals($queryCount, $this->getCurrentQueryCount(), "Removing a new entity should cause no query to be executed.");

        // Test One to Many removal with Entity state as clean
        $this->_em->persist($article);
        $this->_em->flush();

        $queryCount = $this->getCurrentQueryCount();

        $user->articles->removeElement($article);

        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount(), "Removing a persisted entity should cause one query to be executed.");
        $this->assertFalse($user->articles->isInitialized(), "Post-Condition: Collection is not initialized.");

        // Test One to Many removal with Entity state as managed
        $article = new \Doctrine\Tests\Models\CMS\CmsArticle();
        $article->topic = "How to not fail anymore on tests";
        $article->text = "That is simple! Just write more tests!";

        $this->_em->persist($article);

        $queryCount = $this->getCurrentQueryCount();

        $user->articles->removeElement($article);

        $this->assertEquals($queryCount, $this->getCurrentQueryCount(), "Removing a managed entity should cause no query to be executed.");
    }

    /**
     *
     */
    public function testRemoveElementManyToMany()
    {
        $user = $this->_em->find('Doctrine\Tests\Models\CMS\CmsUser', $this->userId);
        $this->assertFalse($user->groups->isInitialized(), "Pre-Condition: Collection is not initialized.");

        // Test Many to Many removal with Entity retrieved from DB
        $group      = $this->_em->find('Doctrine\Tests\Models\CMS\CmsGroup', $this->groupId);
        $queryCount = $this->getCurrentQueryCount();

        $user->groups->removeElement($group);

        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount(), "Removing a persisted entity should cause one query to be executed.");
        $this->assertFalse($user->groups->isInitialized(), "Post-Condition: Collection is not initialized.");

        // Test Many to Many removal with Entity state as new
        $group = new \Doctrine\Tests\Models\CMS\CmsGroup();
        $group->name = "A New group!";

        $queryCount = $this->getCurrentQueryCount();

        $user->groups->removeElement($group);

        $this->assertEquals($queryCount, $this->getCurrentQueryCount(), "Removing new entity should cause no query to be executed.");
        $this->assertFalse($user->groups->isInitialized(), "Post-Condition: Collection is not initialized.");

        // Test Many to Many removal with Entity state as clean
        $this->_em->persist($group);
        $this->_em->flush();

        $queryCount = $this->getCurrentQueryCount();

        $user->groups->removeElement($group);

        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount(), "Removing a persisted entity should cause one query to be executed.");
        $this->assertFalse($user->groups->isInitialized(), "Post-Condition: Collection is not initialized.");

        // Test Many to Many removal with Entity state as managed
        $group = new \Doctrine\Tests\Models\CMS\CmsGroup();
        $group->name = "A New group!";

        $this->_em->persist($group);

        $queryCount = $this->getCurrentQueryCount();

        $user->groups->removeElement($group);

        $this->assertEquals($queryCount, $this->getCurrentQueryCount(), "Removing a managed entity should cause no query to be executed.");
        $this->assertFalse($user->groups->isInitialized(), "Post-Condition: Collection is not initialized.");
    }

    /**
     *
     */
    public function testRemoveElementManyToManyInverse()
    {
        $group = $this->_em->find('Doctrine\Tests\Models\CMS\CmsGroup', $this->groupId);
        $this->assertFalse($group->users->isInitialized(), "Pre-Condition: Collection is not initialized.");

        $user       = $this->_em->find('Doctrine\Tests\Models\CMS\CmsUser', $this->userId);
        $queryCount = $this->getCurrentQueryCount();

        $group->users->removeElement($user);

        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount(), "Removing a managed entity should cause one query to be executed.");
        $this->assertFalse($user->groups->isInitialized(), "Post-Condition: Collection is not initialized.");

        $newUser = new \Doctrine\Tests\Models\CMS\CmsUser();
        $newUser->name = "A New group!";

        $queryCount = $this->getCurrentQueryCount();

        $group->users->removeElement($newUser);

        $this->assertEquals($queryCount, $this->getCurrentQueryCount(), "Removing a new entity should cause no query to be executed.");
        $this->assertFalse($user->groups->isInitialized(), "Post-Condition: Collection is not initialized.");
    }

    /**
     * @group DDC-1399
     */
    public function testCountAfterAddThenFlush()
    {
        $user = $this->_em->find('Doctrine\Tests\Models\CMS\CmsUser', $this->userId);

        $newGroup = new \Doctrine\Tests\Models\CMS\CmsGroup();
        $newGroup->name = "Test4";

        $user->addGroup($newGroup);
        $this->_em->persist($newGroup);

        $this->assertFalse($user->groups->isInitialized());
        $this->assertEquals(4, count($user->groups));
        $this->assertFalse($user->groups->isInitialized());

        $this->_em->flush();

        $this->assertEquals(4, count($user->groups));
    }

    /**
     * @group DDC-1462
     */
    public function testSliceOnDirtyCollection()
    {
        $user = $this->_em->find('Doctrine\Tests\Models\CMS\CmsUser', $this->userId);
        /* @var $user CmsUser */

        $newGroup = new \Doctrine\Tests\Models\CMS\CmsGroup();
        $newGroup->name = "Test4";

        $user->addGroup($newGroup);
        $this->_em->persist($newGroup);

        $qc = $this->getCurrentQueryCount();
        $groups = $user->groups->slice(0, 10);

        $this->assertEquals(4, count($groups));
        $this->assertEquals($qc + 1, $this->getCurrentQueryCount());
    }

    /**
     * @group DDC-1398
     */
    public function testGetIndexByIdentifier()
    {
        $user = $this->_em->find('Doctrine\Tests\Models\CMS\CmsUser', $this->userId);
        /* @var $user CmsUser */

        $queryCount = $this->getCurrentQueryCount();

        $phonenumber = $user->phonenumbers->get($this->phonenumber);

        $this->assertFalse($user->phonenumbers->isInitialized());
        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount());
        $this->assertSame($phonenumber, $this->_em->find('Doctrine\Tests\Models\CMS\CmsPhonenumber', $this->phonenumber));

        $article = $user->phonenumbers->get($this->phonenumber);
        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount(), "Getting the same entity should not cause an extra query to be executed");
    }

    /**
     * @group DDC-1398
     */
    public function testGetIndexByOneToMany()
    {
        $user = $this->_em->find('Doctrine\Tests\Models\CMS\CmsUser', $this->userId);
        /* @var $user CmsUser */

        $queryCount = $this->getCurrentQueryCount();

        $article = $user->articles->get($this->topic);

        $this->assertFalse($user->articles->isInitialized());
        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount());
        $this->assertSame($article, $this->_em->find('Doctrine\Tests\Models\CMS\CmsArticle', $this->articleId));
    }

    /**
     * @group DDC-1398
     */
    public function testGetNonExistentIndexBy()
    {
        $user = $this->_em->find('Doctrine\Tests\Models\CMS\CmsUser', $this->userId);
        $this->assertNull($user->articles->get(-1));
    }

    private function loadFixture()
    {
        $user1 = new \Doctrine\Tests\Models\CMS\CmsUser();
        $user1->username = "beberlei";
        $user1->name = "Benjamin";
        $user1->status = "active";

        $user2 = new \Doctrine\Tests\Models\CMS\CmsUser();
        $user2->username = "jwage";
        $user2->name = "Jonathan";
        $user2->status = "active";

        $user3 = new \Doctrine\Tests\Models\CMS\CmsUser();
        $user3->username = "romanb";
        $user3->name = "Roman";
        $user3->status = "active";

        $user4 = new \Doctrine\Tests\Models\CMS\CmsUser();
        $user4->username = "gblanco";
        $user4->name = "Guilherme";
        $user4->status = "active";

        $this->_em->persist($user1);
        $this->_em->persist($user2);
        $this->_em->persist($user3);
        $this->_em->persist($user4);

        $group1 = new \Doctrine\Tests\Models\CMS\CmsGroup();
        $group1->name = "Test1";

        $group2 = new \Doctrine\Tests\Models\CMS\CmsGroup();
        $group2->name = "Test2";

        $group3 = new \Doctrine\Tests\Models\CMS\CmsGroup();
        $group3->name = "Test3";

        $user1->addGroup($group1);
        $user1->addGroup($group2);
        $user1->addGroup($group3);

        $user2->addGroup($group1);
        $user3->addGroup($group1);
        $user4->addGroup($group1);

        $this->_em->persist($group1);
        $this->_em->persist($group2);
        $this->_em->persist($group3);

        $article1 = new \Doctrine\Tests\Models\CMS\CmsArticle();
        $article1->topic = "Test1";
        $article1->text = "Test1";
        $article1->setAuthor($user1);

        $article2 = new \Doctrine\Tests\Models\CMS\CmsArticle();
        $article2->topic = "Test2";
        $article2->text = "Test2";
        $article2->setAuthor($user1);

        $this->_em->persist($article1);
        $this->_em->persist($article2);

        $phonenumber1 = new \Doctrine\Tests\Models\CMS\CmsPhonenumber();
        $phonenumber1->phonenumber = '12345';

        $phonenumber2 = new \Doctrine\Tests\Models\CMS\CmsPhonenumber();
        $phonenumber2->phonenumber = '67890';

        $this->_em->persist($phonenumber1);
        $this->_em->persist($phonenumber2);

        $this->_em->flush();
        $this->_em->clear();

        $this->articleId = $article1->id;
        $this->userId = $user1->getId();
        $this->groupId = $group1->id;

        $this->topic = $article1->topic;
        $this->phonenumber = $phonenumber1->phonenumber;

    }

    /**
     * @group DDC-3343
     */
    public function testRemoveManagedElementFromOneToManyExtraLazyCollectionIsNoOp()
    {
        list($userId, $tweetId) = $this->loadTweetFixture();

        /* @var $user User */
        $user = $this->_em->find(User::CLASSNAME, $userId);

        $user->tweets->removeElement($this->_em->find(Tweet::CLASSNAME, $tweetId));

        $this->_em->clear();

        /* @var $user User */
        $user = $this->_em->find(User::CLASSNAME, $userId);

        $this->assertCount(1, $user->tweets, 'Element was not removed - need to update the owning side first');
    }

    /**
     * @group DDC-3343
     */
    public function testRemoveManagedElementFromOneToManyExtraLazyCollectionWithoutDeletingTheTargetEntityEntryIsNoOp()
    {
        list($userId, $tweetId) = $this->loadTweetFixture();

        /* @var $user User */
        $user  = $this->_em->find(User::CLASSNAME, $userId);

        $e = $this->_em->find(Tweet::CLASSNAME, $tweetId);

        $user->tweets->removeElement($e);

        $this->_em->clear();

        /* @var $tweet Tweet */
        $tweet = $this->_em->find(Tweet::CLASSNAME, $tweetId);
        $this->assertInstanceOf(
            Tweet::CLASSNAME,
            $tweet,
            'Even though the collection is extra lazy, the tweet should not have been deleted'
        );

        $this->assertInstanceOf(
            User::CLASSNAME,
            $tweet->author,
            'Tweet author link has not been removed - need to update the owning side first'
        );
    }

    /**
     * @group DDC-3343
     */
    public function testRemovingManagedLazyProxyFromExtraLazyOneToManyDoesRemoveTheAssociationButNotTheEntity()
    {
        list($userId, $tweetId) = $this->loadTweetFixture();

        /* @var $user User */
        $user  = $this->_em->find(User::CLASSNAME, $userId);
        $tweet = $this->_em->getReference(Tweet::CLASSNAME, $tweetId);

        $user->tweets->removeElement($this->_em->getReference(Tweet::CLASSNAME, $tweetId));

        $this->_em->clear();

        /* @var $tweet Tweet */
        $tweet = $this->_em->find(Tweet::CLASSNAME, $tweet->id);
        $this->assertInstanceOf(
            Tweet::CLASSNAME,
            $tweet,
            'Even though the collection is extra lazy, the tweet should not have been deleted'
        );

        $this->assertInstanceOf(User::CLASSNAME, $tweet->author);

        /* @var $user User */
        $user = $this->_em->find(User::CLASSNAME, $userId);

        $this->assertCount(1, $user->tweets, 'Element was not removed - need to update the owning side first');
    }

    /**
     * @return int[] ordered tuple: user id and tweet id
     */
    private function loadTweetFixture()
    {
        $user  = new User();
        $tweet = new Tweet();

        $user->name     = 'ocramius';
        $tweet->content = 'The cat is on the table';

        $user->addTweet($tweet);

        $this->_em->persist($user);
        $this->_em->persist($tweet);
        $this->_em->flush();
        $this->_em->clear();

        return array($user->id, $tweet->id);
    }
}
