<?php

namespace Adldap\Tests\Models;

use DateTime;
use Adldap\Utilities;
use Adldap\Models\User;
use Adldap\Models\Entry;
use Adldap\Query\Builder;
use Adldap\Tests\TestCase;
use Adldap\Models\Attributes\TSProperty;
use Adldap\Models\Attributes\TSPropertyArray;

class UserTest extends TestCase
{
    protected function newUserModel(array $attributes = [], $builder = null)
    {
        $builder = $builder ?: $this->newBuilder();

        return new User($attributes, $builder);
    }

    public function test_set_password()
    {
        $connection = $this->newConnectionMock();

        $connection->shouldReceive('isUsingSSL')->once()->andReturn(true);

        $user = new User([], $this->newBuilder($connection));

        $user->setPassword('');

        $expected = [
            [
                'attrib'    => 'unicodepwd',
                'modtype'   => 3,
                'values'    => [Utilities::encodePassword('')],
            ],
        ];

        $this->assertEquals($expected, $user->getModifications());
    }

    /**
     * @expectedException \Adldap\AdldapException
     */
    public function test_set_password_without_ssl_or_tls()
    {
        $connection = $this->newConnectionMock();

        $connection->shouldReceive('isUsingSSL')->once()->andReturn(false);
        $connection->shouldReceive('isUsingTLS')->once()->andReturn(false);

        $user = new User([], $this->newBuilder($connection));

        $user->setPassword('');
    }

    /**
     * @expectedException \Adldap\Models\UserPasswordPolicyException
     */
    public function test_change_password_policy_failure()
    {
        $connection = $this->newConnectionMock();

        $connection->shouldReceive('isUsingSSL')->once()->andReturn(true);
        $connection->shouldReceive('modifyBatch')->once()->andReturn(false);
        $connection->shouldReceive('getExtendedError')->once()->andReturn('error');
        $connection->shouldReceive('getExtendedErrorCode')->once()->andReturn('0000052D');

        $user = new User([], $this->newBuilder($connection));

        $user->changePassword('', '');
    }

    /**
     * @expectedException \Adldap\Models\UserPasswordIncorrectException
     */
    public function test_change_password_wrong_failure()
    {
        $connection = $this->newConnectionMock();

        $connection->shouldReceive('isUsingSSL')->once()->andReturn(true);
        $connection->shouldReceive('modifyBatch')->once()->andReturn(false);
        $connection->shouldReceive('getExtendedError')->once()->andReturn('error');
        $connection->shouldReceive('getExtendedErrorCode')->once()->andReturn('00000056');

        $user = new User([], $this->newBuilder($connection));

        $user->changePassword('', '');
    }

    /**
     * @expectedException \Adldap\AdldapException
     */
    public function test_change_password_without_ssl_or_tls()
    {
        $connection = $this->newConnectionMock();

        $connection->shouldReceive('isUsingSSL')->once()->andReturn(false);
        $connection->shouldReceive('isUsingTLS')->once()->andReturn(false);

        $user = new User([], $this->newBuilder($connection));

        $user->changePassword('', '');
    }

    public function test_set_thumbnail_photo_encodes_images()
    {
        $png = file_get_contents(__DIR__.'/../stubs/placeholder.png');

        $model = $this->newUserModel();

        $model->setThumbnail($png);

        $this->assertEquals(base64_encode($png), $model->getThumbnail());
    }

    public function test_set_jpeg_photo_encodes_images()
    {
        $jpeg = file_get_contents(__DIR__.'/../stubs/placeholder.jpg');

        $model = $this->newUserModel();

        $model->setJpegPhoto($jpeg);

        $this->assertEquals(base64_encode($jpeg), $model->getJpegPhoto());
    }

    public function test_set_user_workstations_accepts_string_or_array()
    {
        $model = $this->newUserModel();

        $model->setUserWorkstations(['ONE','TWO','THREE']);

        $this->assertEquals('ONE,TWO,THREE', $model->getFirstAttribute('userworkstations'));

        $model->setUserWorkstations('ONE,TWO,THREE');

        $this->assertEquals('ONE,TWO,THREE', $model->getFirstAttribute('userworkstations'));
    }

    public function test_get_user_workstations()
    {
        $model = $this->newUserModel([
            'userworkstations' => 'ONE,TWO,THREE',
        ]);

        $this->assertEquals(['ONE','TWO','THREE'], $model->getUserWorkstations());

        $model->userworkstations = 'ONE,';

        $this->assertEquals(['ONE'], $model->getUserWorkstations());

        $model->userworkstations = 'ONE,TWO';

        $this->assertEquals(['ONE','TWO'], $model->getUserWorkstations());
    }

    public function test_get_user_workstations_always_returns_array_when_empty_or_null()
    {
        $model = $this->newUserModel([
            'userworkstations' => null,
        ]);

        $this->assertEquals([], $model->getUserWorkstations());

        $model->userworkstations = '';

        $this->assertEquals([], $model->getUserWorkstations());
    }

    public function test_expiration_date()
    {
        $model = $this->newUserModel([
            'accountexpires' => '131618268000000000',
        ]);

        $date = $model->expirationDate();

        $this->assertInstanceOf(DateTime::class, $date);
        $this->assertEquals(1517353200, $date->getTimestamp());
        $this->assertTrue($model->isExpired());
    }

    public function test_expiration_date_with_max_date()
    {
        $model = $this->newUserModel([
            'accountexpires' => '2650467708000000000',
        ]);

        $date = $model->expirationDate();

        $this->assertInstanceOf(DateTime::class, $date);
        $this->assertEquals(253402297200, $date->getTimestamp());
        $this->assertFalse($model->isExpired());
    }

    public function test_password_is_expired_with_zero_value()
    {
        $model = $this->newUserModel(['pwdlastset' => '0']);

        $this->assertTrue($model->passwordExpired());
    }

    public function test_password_is_expired_with_max_age()
    {
        $sixtyOneDaysAgo = (new DateTime('61 days ago'))->getTimestamp();

        $pwdLastSet = Utilities::convertUnixTimeToWindowsTime($sixtyOneDaysAgo);

        $user = $this->newUserModel(['pwdlastset' => $pwdLastSet]);

        $builder = $this->mock(Builder::class);

        $rootDomainObject = $this->mock(Entry::class);

        // 60 Day expiry time.
        $rootDomainObject->shouldReceive('getMaxPasswordAge')->once()->andReturn('-51840000000000');

        $builder
            ->shouldReceive('newInstance')->once()->andReturnSelf()
            ->shouldReceive('select')->once()->with('maxpwdage')->andReturnSelf()
            ->shouldReceive('whereHas')->once()->with('objectclass')->andReturnSelf()
            ->shouldReceive('first')->once()->andReturn($rootDomainObject);

        $user->setQuery($builder);

        $this->assertTrue($user->passwordExpired());
    }

    public function test_password_is_not_expired_with_max_age()
    {
        $fiftyNineDaysAgo = (new DateTime('59 days ago'))->getTimestamp();

        $pwdLastSet = Utilities::convertUnixTimeToWindowsTime($fiftyNineDaysAgo);

        $user = $this->newUserModel(['pwdlastset' => $pwdLastSet]);

        $builder = $this->mock(Builder::class);

        $rootDomainObject = $this->mock(Entry::class);

        // 60 Day expiry time.
        $rootDomainObject->shouldReceive('getMaxPasswordAge')->once()->andReturn('-51840000000000');

        $builder
            ->shouldReceive('newInstance')->once()->andReturnSelf()
            ->shouldReceive('select')->once()->with('maxpwdage')->andReturnSelf()
            ->shouldReceive('whereHas')->once()->with('objectclass')->andReturnSelf()
            ->shouldReceive('first')->once()->andReturn($rootDomainObject);

        $user->setQuery($builder);

        $this->assertFalse($user->passwordExpired());
    }

    public function test_get_userparameters()
    {
        $model = $this->newUserModel([
            'userparameters' => (new TSPropertyArray(['CtxInitialProgram'=>'C:\\path\\bin.exe','CtxWorkDirectory'=>'C:\\path\\']))->toBinary()
        ]);

        $parameters = $model->getUserParameters();

        $this->assertInstanceOf(TSPropertyArray::class, $parameters);
        $this->assertTrue($parameters->has('CtxInitialProgram'));
        $this->assertFalse($parameters->has('PropertyDoesNotExist'));
        $this->assertEquals('C:\\path\\', $parameters->get('CtxWorkDirectory'));
    }

    public function test_set_user_parameters()
    {
        $model = $this->newUserModel([
            'userparameters' => (new TSPropertyArray(['CtxInitialProgram'=>'C:\\path\\bin.exe','CtxWorkDirectory'=>'C:\\path\\']))->toBinary()
        ]);

        $parameters = $model->getUserParameters();

        $parameters->set('CtxInitialProgram', 'C:\\path\\otherbin.exe');

        $model->setUserParameters($parameters);

        $this->assertTrue($parameters->has('CtxInitialProgram'));
        $this->assertEquals('C:\\path\\otherbin.exe', $parameters->get('CtxInitialProgram'));
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function test_set_non_existant_user_parameters()
    {
        $model = $this->newUserModel([
            'userparameters' => (new TSPropertyArray(['CtxInitialProgram'=>'C:\\path\\bin.exe','CtxWorkDirectory'=>'C:\\path\\']))->toBinary()
        ]);

        $parameters = $model->getUserParameters();

        $parameters->set('CtxWFHomeDir', '/home/');
    }

    public function test_add_user_parameters()
    {
        $model = $this->newUserModel([
            'userparameters' => (new TSPropertyArray(['CtxInitialProgram'=>'C:\\path\\bin.exe','CtxWorkDirectory'=>'C:\\path\\']))->toBinary()
        ]);

        $parameters = $model->getUserParameters();

        $parameters->add((new TSProperty())->setName('CtxInitialProgram')->setValue('C:\\path\\otherbin.exe'));

        $this->assertTrue($parameters->has('CtxInitialProgram'));
        $this->assertEquals('C:\\path\\otherbin.exe', $parameters->get('CtxInitialProgram'));
    }
}
