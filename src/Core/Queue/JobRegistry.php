<?php

namespace SpsFW\Core\Queue;

use RuntimeException;
use SpsFW\Core\DI\DIContainer;
use SpsFW\Core\Queue\Interfaces\JobHandlerInterface;
use SpsFW\Core\Queue\Interfaces\JobInterface;
use SpsFW\Core\Queue\Interfaces\PayloadJobInterface;

class JobRegistry
{


    private array $registeredJobs = [];

    public function __construct(string $cachePath = __DIR__ . "/../../../../../../.cache")
    {
        $this->fromCache($cachePath);
    }

    public function getRegisteredJobs(): array
    {
        $normalized = [];
        foreach ($this->registeredJobs as $jobName => $entry) {
            $normalized[$jobName] = $this->normalizeEntry($entry);
        }
        return $normalized;
    }


    /**
     * Загружает маппинг из кеша
     *
     * @param string $cachePath — путь к кешу (например, var/cache)
     * @return self
     */
    public static function loadFromCache(string $cachePath = __DIR__ . "/../../../../../../.cache"): self
    {
        $instance = new self();
        $cacheFile = $cachePath . '/job_registry.php';

        if (file_exists($cacheFile)) {
            $map = require $cacheFile;
            if (is_array($map)) {
                $instance->registeredJobs = $map;
            }
        }

        return $instance;
    }

    public function fromCache(string $cachePath = __DIR__ . "/../../../../../../.cache"): self
    {
        $cacheFile = $cachePath . '/job_registry.php';

        if (file_exists($cacheFile)) {
            $map = require $cacheFile;
            if (is_array($map)) {
                $this->registeredJobs = $map;
            }
        }

        return $this;
    }


    public function registerJob(string $jobName, string $jobClass, ?string $handlerClass = null): void
    {
        if (!is_subclass_of($jobClass, JobInterface::class)) {
            throw new \InvalidArgumentException("$jobClass must implement JobInterface");
        }

        if ($handlerClass) {
            if (!is_subclass_of($handlerClass, JobHandlerInterface::class)) {
                throw new \InvalidArgumentException("$handlerClass must implement JobHandlerInterface");
            }
        }

        $this->registeredJobs[$jobName] = [
            'jobClass' =>$jobClass,
            'handlerClass' => $handlerClass ?? null
        ];

    }


    /**
     * Регистрирует задачу вручную (для тестов или динамических задач)
     */
    public function register(string $jobName, string $jobClass): void
    {
        if (!is_subclass_of($jobClass, JobInterface::class)) {
            throw new \InvalidArgumentException("Class $jobClass must implement JobInterface");
        }
        $this->registeredJobs[$jobName] = $jobClass;
    }

    public function getJobClass(string $jobName): ?string
    {
        $entry = $this->registeredJobs[$jobName] ?? null;
        if (is_string($entry)) {
            return $entry;
        }
        return $entry['jobClass'] ?? null;
    }

    public function getHandlerClass(string $jobName): ?string
    {
        $entry = $this->registeredJobs[$jobName] ?? null;
        if (!is_array($entry)) {
            return null;
        }
        return $entry['handlerClass'] ?? null;
    }

    public function createJob(string $jobName, string|array $payload): JobInterface
    {
        /** @var class-string $jobClass */
        $jobClass = $this->getJobClass($jobName);
        if (!$jobClass) {
            throw new RuntimeException("Unknown job: $jobName");
        }

        if (is_subclass_of($jobClass, PayloadJobInterface::class)) {
            if (is_string($payload)) {
                $decoded = json_decode($payload, true);
                if (!is_array($decoded)) {
                    throw new RuntimeException("Payload for $jobName must be a JSON object");
                }
                $payload = $decoded;
            }

            return $jobClass::fromPayload($payload);
        }

        // Legacy path: keep backward compatibility with serialize()/deserialize().
        if (is_array($payload)) {
            $payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        return $jobClass::deserialize($payload);
    }

    public function deserialize(string $jobName, string $payload): JobInterface
    {
        /** @var class-string $jobClass */
        $jobClass = $this->getJobClass($jobName);
        if (!$jobClass) {
            throw new \DomainException("Unknown job type: $jobName. Did you forget #[QueueJob] attribute?");
        }

        if (is_subclass_of($jobClass, PayloadJobInterface::class)) {
            $decoded = json_decode($payload, true);
            if (!is_array($decoded)) {
                throw new \DomainException("Payload for $jobName must be a JSON object");
            }
            return $jobClass::fromPayload($decoded);
        }

        return $jobClass::deserialize($payload);
    }

    public function getHandler(string $jobName): JobHandlerInterface
    {
        $handlerClass = $this->getHandlerClass($jobName);
        if (!$handlerClass) {
            throw new RuntimeException("No handler registered for job: $jobName");
        }

        // Use DI container to create handler with dependencies
        return DIContainer::getInstance()->get($handlerClass);
    }

    private function normalizeEntry(mixed $entry): array
    {
        if (is_string($entry)) {
            return [
                'jobClass' => $entry,
                'handlerClass' => null,
            ];
        }

        if (is_array($entry)) {
            return [
                'jobClass' => $entry['jobClass'] ?? null,
                'handlerClass' => $entry['handlerClass'] ?? null,
            ];
        }

        return [
            'jobClass' => null,
            'handlerClass' => null,
        ];
    }

    /**
     * Helper: auto-register by attributes on provided list of classes (strings)
     * Expects array of fully-qualified class names.
     */
//    public function autoRegisterFromClasses(array $classList): void
//    {
//        foreach ($classList as $className) {
//            if (!class_exists($className)) continue;
//            $rc = new \ReflectionClass($className);
//            $attrs = $rc->getAttributes();
//            foreach ($attrs as $attr) {
//                $attrName = $attr->getName();
//                if ($attrName === \SpsFW\Core\Queue\Attributes\QueueJob::class) {
//                    $args = $attr->getArguments();
//                    $jobName = $args[0] ?? null;
//                    if ($jobName) {
//                        $this->registerJob($jobName, $className);
//                    }
//                }
//                if ($attrName === \SpsFW\Core\Queue\Attributes\JobHandler::class) {
//                    $args = $attr->getArguments();
//                    $jobClass = $args[0] ?? null;
//                    if ($jobClass) {
//                        // find job name by jobClass
//                        foreach ($this->jobs as $name => $jClass) {
//                            if ($jClass === $jobClass) {
//                                $this->handlers[$name] = $className;
//}
//                        }
//                    }
//                }
//            }
//        }
//    }
}
