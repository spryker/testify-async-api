<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\TestifyAsyncApi\Business\Codeception\Helper;

use Codeception\Module;
use Codeception\TestInterface;
use Exception;
use Generated\Shared\Transfer\MessagePropertiesValidationRequestTransfer;
use Generated\Shared\Transfer\MessagePropertiesValidationResponseTransfer;
use Spryker\Shared\Kernel\Transfer\AbstractTransfer;
use Spryker\Shared\MessageBroker\MessageBrokerConstants;
use SprykerSdk\AsyncApi\AsyncApi\AsyncApiInterface;
use SprykerSdk\AsyncApi\AsyncApi\Channel\AsyncApiChannelInterface;
use SprykerSdk\AsyncApi\AsyncApi\Loader\AsyncApiLoader;
use SprykerSdk\AsyncApi\AsyncApi\Message\AsyncApiMessageInterface;
use SprykerSdk\AsyncApi\AsyncApi\Message\Attributes\AsyncApiMessageAttributeCollectionInterface;
use SprykerTest\Shared\Testify\Helper\ConfigHelperTrait;
use SprykerTest\Zed\MessageBroker\Helper\InMemoryMessageBrokerHelper;
use SprykerTest\Zed\MessageBroker\Helper\InMemoryMessageBrokerHelperTrait;
use SprykerTest\Zed\Testify\Helper\Business\BusinessHelperTrait;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Throwable;

class AsyncApiHelper extends Module
{
    use BusinessHelperTrait;
    use InMemoryMessageBrokerHelperTrait;
    use ConfigHelperTrait;

    /**
     * @var string
     */
    protected const ASYNC_API_FILE_PATH = 'asyncapi';

    /**
     * @var string
     */
    protected const MESSAGE_HANDLER_PLUGINS = 'handlers';

    /**
     * @var array<int, string>
     */
    protected array $requiredFields = [
        self::ASYNC_API_FILE_PATH,
    ];

    /**
     * @var array<string, mixed>
     */
    protected array $config = [
        'organization' => 'SprykerTest',
        'selfTest' => 'false', # Needs to be set to `true` when testing this Helper, inside the test you can call `setAsyncApi` which will then be loaded for the tests
        self::MESSAGE_HANDLER_PLUGINS => [],
    ];

    /**
     * We can not use _beforeSuite method to initialize as we could run tests with the help of the SuiteFilterHelper.
     * Using the SuiteFilterHelper would still call the _beforeSuite method but no tests at all. We now check before each test
     * if the AsyncAPI tests have been initialized and if not we initialize them. With this, the first executed test would
     * ensure that all messages are tested.
     *
     * @var bool
     */
    protected bool $isInitialized = false;

    /**
     * @var \SprykerSdk\AsyncApi\AsyncApi\AsyncApiInterface
     */
    protected AsyncApiInterface $asyncApi;

    /**
     * @var array<string, string>
     */
    protected array $channelMessagesToBeValidated = [];

    /**
     * @var bool
     */
    protected bool $hasFailed = false;

    /**
     * @param \Codeception\TestInterface $test
     *
     * @return void
     */
    public function _before(TestInterface $test): void
    {
        // When we test this Helper we want to load a specific schema file from within the test. This will skip loading of the default schema file.
        if ($this->config['selfTest'] === true) {
            return;
        }

        $this->getConfigHelper()->setConfig(MessageBrokerConstants::IS_ENABLED, true);

        if (!$this->isInitialized) {
            $path = codecept_absolute_path('../../../../' . $this->config[static::ASYNC_API_FILE_PATH]);

            $this->initAsyncApiTests($path);
        }

        parent::_before($test);
    }

    /**
     * @param string $asyncApiSchemaFilePath
     *
     * @return void
     */
    public function setAsyncApi(string $asyncApiSchemaFilePath): void
    {
        $this->initAsyncApiTests($asyncApiSchemaFilePath);
    }

    /**
     * @param string $pathToAsyncApiSchemaFile
     *
     * @throws \Exception
     *
     * @return void
     */
    protected function initAsyncApiTests(string $pathToAsyncApiSchemaFile): void
    {
        if (!is_file($pathToAsyncApiSchemaFile)) {
            throw new Exception(sprintf('Tried to load your defined AsyncAPI schema file "%s" but was not able to  find it.', $pathToAsyncApiSchemaFile));
        }

        $asyncApiFilePath = realpath($pathToAsyncApiSchemaFile);

        $asyncApi = (new AsyncApiLoader())->load((string)$asyncApiFilePath);

        foreach ($asyncApi->getChannels() as $channelName => $channel) {
            $this->validateChannel($channelName, $channel);
        }

        $this->asyncApi = $asyncApi;

        $this->isInitialized = true;
    }

    /**
     * @return void
     */
    public function _afterSuite(): void
    {
        if (!$this->channelMessagesToBeValidated || $this->hasFailed) {
            return;
        }

        foreach ($this->channelMessagesToBeValidated as $channelNameMessageNameKey) {
            codecept_debug(sprintf('"%s" was not tested.', $channelNameMessageNameKey));
        }

        $this->assertEmpty($this->channelMessagesToBeValidated, sprintf(
            'Expected that all messages in all channels are tested but "%s" %s missing. The following messages are not tested: "%s"',
            count($this->channelMessagesToBeValidated),
            count($this->channelMessagesToBeValidated) === 1 ? 'is' : 'are',
            implode(', ', $this->channelMessagesToBeValidated),
        ));

        parent::_afterSuite();
    }

    /**
     * @param \Codeception\TestInterface $test
     * @param \Exception $fail
     *
     * @return void
     */
    public function _failed(TestInterface $test, Exception $fail): void
    {
        $this->hasFailed = true;

        parent::_failed($test, $fail);
    }

    /**
     * @param \Spryker\Shared\Kernel\Transfer\AbstractTransfer $messageTransfer
     * @param string $channelNameToTest
     * @param callable|null $assertion
     *
     * @throws \Throwable
     *
     * @return void
     */
    public function assertMessageWasEmittedOnChannel(AbstractTransfer $messageTransfer, string $channelNameToTest, ?callable $assertion = null): void
    {
        $messageNameToTest = $this->getMessageNameToTestFromTransferClass($messageTransfer);

        $asyncApiMessageChannel = $this->getAsyncApiChannelByChannelName($channelNameToTest);
        $asyncApiMessage = $this->getAsyncApiSubscribeMessageInChannelByMessageName($channelNameToTest, $messageNameToTest);

        try {
            $this->assertNotNull($asyncApiMessageChannel, sprintf('Expected channel by name "%s" was not found.', $channelNameToTest));
            $this->assertNotNull($asyncApiMessage, sprintf('Expected message by name "%s" was not found.', $messageNameToTest));

            $this->getInMemoryMessageBrokerHelper()->assertMessageWasSent($messageTransfer::class);

            $sentMessageTransfer = $this->getInMemoryMessageBrokerHelper()->getMessageTransferByMessageName($messageTransfer::class);

            $this->assertMessageRequiredPropertiesValidationIsSuccessful($messageNameToTest, $sentMessageTransfer, $asyncApiMessage);

            // Callable can be passed from outside and must accept the Message that was expected to be sent e.g.
            // AsyncApiHelper::assertMessageWasEmittedOnChannel(\Generated\Shared\Transfer\SomethingHappenedTransfer, 'foo-events', function (\Generated\Shared\Transfer\SomethingHappenedTransfer $expectedMessageTransfer, \Generated\Shared\Transfer\SomethingHappenedTransfer $sentMessageTransfer) {
            //     // Do your assertions here
            // });
            if ($assertion) {
                $assertion($messageTransfer, $sentMessageTransfer);
            }

            $this->markMessageInChannelHandled($channelNameToTest, $messageNameToTest);
        } catch (Throwable $e) {
            $this->hasFailed = true;

            throw $e;
        }
    }

    /**
     * @param string $channelNameToTest
     *
     * @throws \Exception
     *
     * @return \SprykerSdk\AsyncApi\AsyncApi\Channel\AsyncApiChannelInterface
     */
    protected function getAsyncApiChannelByChannelName(string $channelNameToTest): AsyncApiChannelInterface
    {
        foreach ($this->asyncApi->getChannels() as $channelName => $channel) {
            if ($channelNameToTest === $channelName) {
                return $channel;
            }
        }

        throw new Exception(sprintf('Could not find the channel "%s" in the current AsyncAPI schema file.', $channelNameToTest));
    }

    /**
     * @param string $channelNameToTest
     * @param string $messageNameToTest
     *
     * @throws \Exception
     *
     * @return \SprykerSdk\AsyncApi\AsyncApi\Message\AsyncApiMessageInterface
     */
    protected function getAsyncApiSubscribeMessageInChannelByMessageName(string $channelNameToTest, string $messageNameToTest): AsyncApiMessageInterface
    {
        $channel = $this->getAsyncApiChannelByChannelName($channelNameToTest);

        foreach ($channel->getSubscribeMessages() as $messageName => $message) {
            if ($messageNameToTest === $messageName) {
                return $message;
            }
        }

        throw new Exception(sprintf('Could not find the message "%s" inside the channel "%s" in the current AsyncAPI schema file.', $messageNameToTest, $channelNameToTest));
    }

    /**
     * @param string $channelNameToTest
     * @param string $messageNameToTest
     *
     * @throws \Exception
     *
     * @return \SprykerSdk\AsyncApi\AsyncApi\Message\AsyncApiMessageInterface
     */
    protected function getAsyncApiPublishMessageInChannelByMessageName(string $channelNameToTest, string $messageNameToTest): AsyncApiMessageInterface
    {
        $channel = $this->getAsyncApiChannelByChannelName($channelNameToTest);

        foreach ($channel->getPublishMessages() as $messageName => $message) {
            if ($messageNameToTest === $messageName) {
                return $message;
            }
        }

        throw new Exception(sprintf('Could not find the message "%s" inside the channel "%s" in the current AsyncAPI schema file.', $messageNameToTest, $channelNameToTest));
    }

    /**
     * @param \Spryker\Shared\Kernel\Transfer\AbstractTransfer $messageTransfer
     *
     * @return string
     */
    protected function getMessageNameToTestFromTransferClass(AbstractTransfer $messageTransfer): string
    {
        $messageNameToTest = explode('\\', $messageTransfer::class);
        $messageNameToTest = array_pop($messageNameToTest);

        return str_replace('Transfer', '', $messageNameToTest);
    }

    /**
     * @param \Spryker\Shared\Kernel\Transfer\AbstractTransfer $messageTransfer
     * @param string $channelNameToTest
     * @param callable|null $handler Use this only when you need to mock the handler.
     *
     * @throws \Throwable
     *
     * @return void
     */
    public function runMessageReceiveTest(AbstractTransfer $messageTransfer, string $channelNameToTest, ?callable $handler = null): void
    {
        $messageNameToTest = $this->getMessageNameToTestFromTransferClass($messageTransfer);

        $asyncApiMessageChannel = $this->getAsyncApiChannelByChannelName($channelNameToTest);
        $asyncApiMessage = $this->getAsyncApiPublishMessageInChannelByMessageName($channelNameToTest, $messageNameToTest);

        if ($handler) {
            $this->assertHandlerCanHandle((array)$handler, $messageTransfer);
        }

        $handler = $handler ?? $this->getHandlerForMessage($messageTransfer);

        try {
            $this->assertNotNull($asyncApiMessageChannel, sprintf('Expected channel by name "%s" was not found.', $channelNameToTest));
            $this->assertNotNull($asyncApiMessage, sprintf('Expected message by name "%s" was not found.', $messageNameToTest));

            $this->assertMessageRequiredPropertiesValidationIsSuccessful($messageNameToTest, $messageTransfer, $asyncApiMessage);
        } catch (Throwable $e) {
            $this->hasFailed = true;

            throw $e;
        }
        // Run the message handler
        $handler($messageTransfer);

        $this->markMessageInChannelHandled($channelNameToTest, $messageNameToTest);
    }

    /**
     * @param string $messageNameToTest
     * @param \Spryker\Shared\Kernel\Transfer\AbstractTransfer $messageTransfer
     * @param \SprykerSdk\AsyncApi\AsyncApi\Message\AsyncApiMessageInterface $asyncApiMessage
     *
     * @return void
     */
    protected function assertMessageRequiredPropertiesValidationIsSuccessful(
        string $messageNameToTest,
        AbstractTransfer $messageTransfer,
        AsyncApiMessageInterface $asyncApiMessage
    ): void {
        $messagePropertiesValidationRequestTransfer = new MessagePropertiesValidationRequestTransfer();
        $messagePropertiesValidationRequestTransfer->setMessageName($messageNameToTest);
        $messagePropertiesValidationRequestTransfer = $this->prepareMessageForValidation($messagePropertiesValidationRequestTransfer, $messageTransfer, $asyncApiMessage);

        $messagePropertiesValidationResponseTransfer = $this->validateMessage($messagePropertiesValidationRequestTransfer);

        $this->assertTrue($messagePropertiesValidationResponseTransfer->getIsSuccessful(), $messagePropertiesValidationResponseTransfer->getErrorMessage() ?? '');
    }

    /**
     * @param \Generated\Shared\Transfer\MessagePropertiesValidationRequestTransfer $messagePropertiesValidationRequestTransfer
     * @param \Spryker\Shared\Kernel\Transfer\AbstractTransfer $messageTransfer
     * @param \SprykerSdk\AsyncApi\AsyncApi\Message\AsyncApiMessageInterface $asyncApiMessage
     *
     * @return \Generated\Shared\Transfer\MessagePropertiesValidationRequestTransfer
     */
    protected function prepareMessageForValidation(
        MessagePropertiesValidationRequestTransfer $messagePropertiesValidationRequestTransfer,
        AbstractTransfer $messageTransfer,
        AsyncApiMessageInterface $asyncApiMessage
    ): MessagePropertiesValidationRequestTransfer {
        $messagePropertiesValidationRequestTransfer = $this->getRequiredAttributesForMessage($messagePropertiesValidationRequestTransfer, $asyncApiMessage);

        $properties = $messageTransfer->modifiedToArray(true, true);
        $messagePropertiesValidationRequestTransfer->setProperties($properties);

        return $messagePropertiesValidationRequestTransfer;
    }

    /**
     * @param \Generated\Shared\Transfer\MessagePropertiesValidationRequestTransfer $messagePropertiesValidationRequestTransfer
     *
     * @return \Generated\Shared\Transfer\MessagePropertiesValidationResponseTransfer
     */
    protected function validateMessage(
        MessagePropertiesValidationRequestTransfer $messagePropertiesValidationRequestTransfer
    ): MessagePropertiesValidationResponseTransfer {
        $propertyAccessor = new PropertyAccessor();

        $missingProperties = [];

        $messagePropertiesValidationResponseTransfer = new MessagePropertiesValidationResponseTransfer();
        $messagePropertiesValidationResponseTransfer->setIsSuccessful(true);

        if (!$messagePropertiesValidationRequestTransfer->getRequiredProperties() && !$messagePropertiesValidationRequestTransfer->getRequiredArrayProperties()) {
            return $messagePropertiesValidationResponseTransfer;
        }

        $missingProperties = $this->getMissingPropertiesFromRequiredProperties($messagePropertiesValidationRequestTransfer, $propertyAccessor, $missingProperties);

        $missingProperties = $this->getMissingPropertiesFromRequiredArrayProperties($messagePropertiesValidationRequestTransfer, $propertyAccessor, $missingProperties);

        if ($missingProperties) {
            $messagePropertiesValidationResponseTransfer->setIsSuccessful(false);
            $messagePropertiesValidationResponseTransfer->setErrorMessage($this->getValidationError($messagePropertiesValidationRequestTransfer, $missingProperties));
        }

        return $messagePropertiesValidationResponseTransfer;
    }

    /**
     * @param \Generated\Shared\Transfer\MessagePropertiesValidationRequestTransfer $messagePropertiesValidationRequestTransfer
     * @param array<int, string> $missingProperties
     *
     * @return string
     */
    protected function getValidationError(
        MessagePropertiesValidationRequestTransfer $messagePropertiesValidationRequestTransfer,
        array $missingProperties
    ): string {
        $requiredProperties = array_merge($messagePropertiesValidationRequestTransfer->getRequiredProperties(), array_column($messagePropertiesValidationRequestTransfer->getRequiredArrayProperties(), 'path'));

        return sprintf('The message "%s" does not contain all required properties "%s". The following properties are missing "%s".', $messagePropertiesValidationRequestTransfer->getMessageName(), implode(', ', $requiredProperties), implode(', ', $missingProperties));
    }

    /**
     * Assert that a passed handler is able to handle the expected message. This covers the MessageHandlerPluginInterface::handles()
     * method which would be uncovered when passed from the test itself.
     *
     * @param array<string, string> $handler
     * @param \Spryker\Shared\Kernel\Transfer\AbstractTransfer $messageTransfer
     *
     * @return void
     */
    protected function assertHandlerCanHandle(array $handler, AbstractTransfer $messageTransfer): void
    {
        /** @var \Spryker\Zed\MessageBrokerExtension\Dependency\Plugin\MessageHandlerPluginInterface $handlerClass */
        $handlerClass = array_shift($handler);
        $handleableMessages = [];

        foreach ($handlerClass->handles() as $messageName => $handler) {
            if ($messageName === $messageTransfer::class) {
                return;
            }
            $handleableMessages[] = $messageName;
        }

        $this->failWithMessage(sprintf(
            'The passed handler "%s" can not handle the message "%s". Supported messages are: "%s"',
            $handlerClass::class,
            $messageTransfer::class,
            implode(', ', $handleableMessages),
        ));
    }

    /**
     * @param \Spryker\Shared\Kernel\Transfer\AbstractTransfer $messageTransfer
     *
     * @throws \Exception
     *
     * @return callable
     */
    protected function getHandlerForMessage(AbstractTransfer $messageTransfer): callable
    {
        foreach ($this->config[static::MESSAGE_HANDLER_PLUGINS] as $handlerClassName) {
            /** @var \Spryker\Zed\MessageBrokerExtension\Dependency\Plugin\MessageHandlerPluginInterface $handlerClass */
            $handlerClass = new $handlerClassName();
            /** @var callable $handler */
            foreach ($handlerClass->handles() as $messageName => $handler) {
                if ($messageName === $messageTransfer::class) {
                    return $handler;
                }
            }
        }

        $this->failWithMessage(sprintf(
            'Could not find a handler for the message "%s". You can add a handler via your codeception.yml to the "%s: handlers: - \Foo\Bar\Handler"',
            $messageTransfer::class,
            static::class,
        ));

        throw new Exception(sprintf(
            'Could not find a handler for the message "%s". You can add a handler via your codeception.yml to the "%s: handlers: - \Foo\Bar\Handler"',
            $messageTransfer::class,
            static::class,
        ));
    }

    /**
     * @param string $message
     *
     * @return void
     */
    protected function failWithMessage(string $message): void
    {
        // Set as failed to not run the _afterSuite checks. When this is not set the error would not be shown but the one
        // with "not all messages are tested" will be printed.
        $this->hasFailed = true;

        $this->fail($message);
    }

    /**
     * @param string $channelName
     * @param \SprykerSdk\AsyncApi\AsyncApi\Channel\AsyncApiChannelInterface $channel
     *
     * @return void
     */
    protected function validateChannel(string $channelName, AsyncApiChannelInterface $channel): void
    {
        foreach ($channel->getPublishMessages() as $publishMessageName => $publishMessage) {
            $this->addMessageInChannelToBeValidated($channelName, $publishMessageName);
        }

        foreach ($channel->getSubscribeMessages() as $subscribeMessageName => $subscribeMessage) {
            $this->addMessageInChannelToBeValidated($channelName, $subscribeMessageName);
        }
    }

    /**
     * @param string $channelName
     * @param string $messageName
     *
     * @return void
     */
    protected function addMessageInChannelToBeValidated(string $channelName, string $messageName): void
    {
        $channelMessageKey = $this->getChannelMessageKey($channelName, $messageName);
        $this->channelMessagesToBeValidated[$channelMessageKey] = $channelMessageKey;
    }

    /**
     * @param string $channelName
     * @param string $messageName
     *
     * @return void
     */
    protected function markMessageInChannelHandled(string $channelName, string $messageName): void
    {
        $channelMessageKey = $this->getChannelMessageKey($channelName, $messageName);
        unset($this->channelMessagesToBeValidated[$channelMessageKey]);
    }

    /**
     * @param string $channelName
     * @param string $messageName
     *
     * @return string
     */
    protected function getChannelMessageKey(string $channelName, string $messageName): string
    {
        return sprintf('%s::%s', $channelName, $messageName);
    }

    /**
     * @param \Generated\Shared\Transfer\MessagePropertiesValidationRequestTransfer $messagePropertiesValidationRequestTransfer
     * @param \SprykerSdk\AsyncApi\AsyncApi\Message\AsyncApiMessageInterface $message
     *
     * @return \Generated\Shared\Transfer\MessagePropertiesValidationRequestTransfer
     */
    protected function getRequiredAttributesForMessage(
        MessagePropertiesValidationRequestTransfer $messagePropertiesValidationRequestTransfer,
        AsyncApiMessageInterface $message
    ): MessagePropertiesValidationRequestTransfer {
        /** @var \SprykerSdk\AsyncApi\AsyncApi\Message\Attributes\AsyncApiMessageAttributeCollectionInterface|null $payloadAttribute */
        $payloadAttribute = $message->getAttribute('payload');

        // In case we have a "marker" message without any payload then we can skip the required field validation.
        if (!$payloadAttribute) {
            return $messagePropertiesValidationRequestTransfer;
        }

        $this->getRequiredAttributes($payloadAttribute, $messagePropertiesValidationRequestTransfer);

        return $messagePropertiesValidationRequestTransfer;
    }

    /**
     * @param \SprykerSdk\AsyncApi\AsyncApi\Message\Attributes\AsyncApiMessageAttributeCollectionInterface $properties
     * @param string $lookupAttributeName
     *
     * @return bool
     */
    protected function hasPropertiesCollectionProperty(AsyncApiMessageAttributeCollectionInterface $properties, string $lookupAttributeName): bool
    {
        foreach ($properties->getAttributes() as $attributeName => $attribute) {
            if ($attributeName === $lookupAttributeName) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param \SprykerSdk\AsyncApi\AsyncApi\Message\Attributes\AsyncApiMessageAttributeCollectionInterface $properties
     * @param string $lookupPropertyName
     *
     * @throws \Exception
     *
     * @return \SprykerSdk\AsyncApi\AsyncApi\Message\Attributes\AsyncApiMessageAttributeCollectionInterface
     */
    protected function getPropertiesCollectionProperty(
        AsyncApiMessageAttributeCollectionInterface $properties,
        string $lookupPropertyName
    ): AsyncApiMessageAttributeCollectionInterface {
        /** @var \SprykerSdk\AsyncApi\AsyncApi\Message\Attributes\AsyncApiMessageAttributeCollectionInterface $attribute */
        foreach ($properties->getAttributes() as $attributeName => $attribute) {
            if ($attributeName !== $lookupPropertyName) {
                continue;
            }

            return $attribute;
        }

        throw new Exception(sprintf('You MUST call "hasPropertiesCollectionProperty" before "getPropertiesCollectionProperty". Property "%s" not found in collection.', $lookupPropertyName));
    }

    /**
     * @param \SprykerSdk\AsyncApi\AsyncApi\Message\Attributes\AsyncApiMessageAttributeCollectionInterface $attribute
     * @param \Generated\Shared\Transfer\MessagePropertiesValidationRequestTransfer $messagePropertiesValidationRequestTransfer
     * @param string $currentKey
     *
     * @return void
     */
    protected function getRequiredAttributes(
        AsyncApiMessageAttributeCollectionInterface $attribute,
        MessagePropertiesValidationRequestTransfer $messagePropertiesValidationRequestTransfer,
        string $currentKey = ''
    ): void {
        /** @var \SprykerSdk\AsyncApi\AsyncApi\Message\Attributes\AsyncApiMessageAttributeCollectionInterface $properties */
        $properties = $attribute->getAttribute('properties');

        /** @var \SprykerSdk\AsyncApi\AsyncApi\Message\Attributes\AsyncApiMessageAttributeCollectionInterface|null $required */
        $required = $attribute->getAttribute('required');

        $isArray = $this->attributeIsArray($attribute);
        if ($isArray && $attribute->getAttribute('items')) {
            [$properties, $required] = $this->getPropertiesAndRequiredFromAttributeArray($attribute);
        }

        if (!$required) {
            return;
        }

        $this->collectRequiredProperties($required, $currentKey, $isArray, $messagePropertiesValidationRequestTransfer, $properties);
    }

    /**
     * @throws \Exception
     *
     * @return \SprykerTest\Zed\MessageBroker\Helper\InMemoryMessageBrokerHelper
     */
    protected function getInMemoryMessageBrokerHelper(): InMemoryMessageBrokerHelper
    {
        if (!$this->hasModule('\\' . InMemoryMessageBrokerHelper::class)) {
            throw new Exception(sprintf('Expected to have a helper "%s" added to your codeception.yml but was not able to find it. Did you forget to add the helper?', InMemoryMessageBrokerHelper::class));
        }

        /** @var \SprykerTest\Zed\MessageBroker\Helper\InMemoryMessageBrokerHelper $helper */
        $helper = $this->getModule('\\' . InMemoryMessageBrokerHelper::class);

        return $helper;
    }

    /**
     * @param \SprykerSdk\AsyncApi\AsyncApi\Message\Attributes\AsyncApiMessageAttributeCollectionInterface $attribute
     *
     * @return bool
     */
    protected function attributeIsArray(AsyncApiMessageAttributeCollectionInterface $attribute): bool
    {
        /** @var \SprykerSdk\AsyncApi\AsyncApi\Message\Attributes\AsyncApiMessageAttributeInterface $attributeType */
        $attributeType = $attribute->getAttribute('type');

        return $attributeType !== null && $attributeType->getValue() === 'array';
    }

    /**
     * @param \SprykerSdk\AsyncApi\AsyncApi\Message\Attributes\AsyncApiMessageAttributeCollectionInterface $attribute
     *
     * @return array<int, mixed>
     */
    protected function getPropertiesAndRequiredFromAttributeArray(AsyncApiMessageAttributeCollectionInterface $attribute): array
    {
        /** @var \SprykerSdk\AsyncApi\AsyncApi\Message\Attributes\AsyncApiMessageAttributeCollectionInterface $items */
        $items = $attribute->getAttribute('items');

        /** @var \SprykerSdk\AsyncApi\AsyncApi\Message\Attributes\AsyncApiMessageAttributeCollectionInterface $properties */
        $properties = $items->getAttribute('properties');

        /** @var \SprykerSdk\AsyncApi\AsyncApi\Message\Attributes\AsyncApiMessageAttributeCollectionInterface|null $required */
        $required = $items->getAttribute('required');

        return [$properties, $required];
    }

    /**
     * @param \SprykerSdk\AsyncApi\AsyncApi\Message\Attributes\AsyncApiMessageAttributeCollectionInterface $required
     * @param string $currentKey
     * @param bool $isArray
     * @param \Generated\Shared\Transfer\MessagePropertiesValidationRequestTransfer $messagePropertiesValidationRequestTransfer
     * @param \SprykerSdk\AsyncApi\AsyncApi\Message\Attributes\AsyncApiMessageAttributeCollectionInterface $properties
     *
     * @return void
     */
    protected function collectRequiredProperties(
        AsyncApiMessageAttributeCollectionInterface $required,
        string $currentKey,
        bool $isArray,
        MessagePropertiesValidationRequestTransfer $messagePropertiesValidationRequestTransfer,
        AsyncApiMessageAttributeCollectionInterface $properties
    ): void {
        foreach ($required->getAttributes() as $requiredAttribute) {
            /** @var string $attributeValue */
            $attributeValue = $requiredAttribute->getValue();
            $key = $currentKey ? sprintf('%s.%s', $currentKey, $attributeValue) : $attributeValue;

            if ($isArray) {
                $messagePropertiesValidationRequestTransfer->addRequiredArrayProperty([
                    'parent' => $currentKey,
                    'path' => $key,
                    'property' => $attributeValue,
                ]);

                continue;
            }

            $messagePropertiesValidationRequestTransfer->addRequiredProperty($key);

            if ($this->hasPropertiesCollectionProperty($properties, $attributeValue)) {
                $this->getRequiredAttributes($this->getPropertiesCollectionProperty($properties, $attributeValue), $messagePropertiesValidationRequestTransfer, $key);
            }
        }
    }

    /**
     * @param \Generated\Shared\Transfer\MessagePropertiesValidationRequestTransfer $messagePropertiesValidationRequestTransfer
     * @param \Symfony\Component\PropertyAccess\PropertyAccessor $propertyAccessor
     * @param array<int, string> $missingProperties
     *
     * @return array<int, string>
     */
    protected function getMissingPropertiesFromRequiredArrayProperties(
        MessagePropertiesValidationRequestTransfer $messagePropertiesValidationRequestTransfer,
        PropertyAccessor $propertyAccessor,
        array $missingProperties
    ): array {
        foreach ($messagePropertiesValidationRequestTransfer->getRequiredArrayProperties() as $requiredArrayProperty) {
            $parentPath = sprintf('[%s]', implode('][', explode('.', $requiredArrayProperty['parent'])));
            $items = $propertyAccessor->getValue($messagePropertiesValidationRequestTransfer->getProperties(), $parentPath);

            if ($items === null) {
                continue;
            }

            foreach ($items as $position => $value) {
                if (!isset($value[$requiredArrayProperty['property']])) {
                    $missingProperties[] = sprintf('%s[%s].%s', $requiredArrayProperty['parent'], $position, $requiredArrayProperty['property']);
                }
            }
        }

        return $missingProperties;
    }

    /**
     * @param \Generated\Shared\Transfer\MessagePropertiesValidationRequestTransfer $messagePropertiesValidationRequestTransfer
     * @param \Symfony\Component\PropertyAccess\PropertyAccessor $propertyAccessor
     * @param array<int, string> $missingProperties
     *
     * @return array<int, string>
     */
    protected function getMissingPropertiesFromRequiredProperties(
        MessagePropertiesValidationRequestTransfer $messagePropertiesValidationRequestTransfer,
        PropertyAccessor $propertyAccessor,
        array $missingProperties
    ): array {
        foreach ($messagePropertiesValidationRequestTransfer->getRequiredProperties() as $requiredProperty) {
            $propertyPath = sprintf('[%s]', implode('][', explode('.', $requiredProperty)));

            if (!$propertyAccessor->isReadable($messagePropertiesValidationRequestTransfer->getProperties(), $propertyPath) || !$propertyAccessor->getValue($messagePropertiesValidationRequestTransfer->getProperties(), $propertyPath)) {
                $missingProperties[] = $requiredProperty;
            }
        }

        return $missingProperties;
    }
}
