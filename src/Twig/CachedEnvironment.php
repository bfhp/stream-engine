<?php

declare(strict_types=1);

namespace StreamEngine\Twig;

use Exception;
use RuntimeException;
use Twig\Environment;
use Twig\Loader\LoaderInterface;
use Twig\TemplateWrapper;

/**
 * Class CachedEnvironment
 *
 * Extends Twig Environment to automatically track loaded templates
 * and their modification timestamps.
 *
 * This environment integrates with {@see TemplateWatcher} to:
 * - Automatically register each loaded template
 * - Track template file modification times
 * - Provide a centralized watcher for cache invalidation logic
 *
 * Typical use case:
 * - Use this environment instead of the default Twig Environment
 * - After rendering, retrieve the watcher and get the latest modification time
 * - Use that timestamp for HTTP caching (ETag / Last-Modified headers)
 *
 * @package StreamEngine\Twig
 */
class CachedEnvironment extends Environment
{
    /**
     * Template watcher instance used for tracking loaded templates.
     *
     * @var TemplateWatcher
     */
    private TemplateWatcher $watcher;

    /**
     * CachedEnvironment constructor.
     *
     * Initializes Twig Environment and starts template tracking immediately.
     *
     * @param LoaderInterface $loader  Twig loader implementation.
     * @param array<string,mixed> $options Twig environment options.
     */
    public function __construct(LoaderInterface $loader, array $options = [])
    {
        parent::__construct($loader, $options);
        $this->watcher = new TemplateWatcher();
        $this->watcher->startTracking();
    }

    /**
     * Loads a template and registers it in the TemplateWatcher.
     *
     * Overrides the base load() method to intercept template loading
     * and record its file path and modification time.
     *
     * @param string|object $name The template name or identifier.
     *
     * @return TemplateWrapper The loaded template wrapper.
     */
    public function load($name): TemplateWrapper
    {
        try {
            $template = parent::load($name);
        } catch (Exception $e) {
            throw new RuntimeException("Template load failed: " . $e->getMessage());
        }
        try {
            $template_path = $this->getLoader()->getSourceContext($name)->getPath();
        } catch (Exception $e) {
            throw new RuntimeException("Template path obtaining failed: " . $e->getMessage());
        }
        $this->watcher->addTemplate($template_path);
        return $template;
    }

    /**
     * Returns the TemplateWatcher instance.
     *
     * Can be used to:
     * - Retrieve last modification timestamp
     * - Force manual updates
     * - Integrate with HTTP caching strategies
     *
     * @return TemplateWatcher
     */
    public function getWatcher(): TemplateWatcher
    {
        return $this->watcher;
    }
}
