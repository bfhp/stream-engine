<?php

declare(strict_types=1);

namespace StreamEngine\Twig;

/**
 * Class TemplateWatcher
 *
 * Tracks Twig template files and determines the latest modification timestamp.
 *
 * This class is designed to:
 * - Start tracking template usage during rendering
 * - Collect modification times of used template files
 * - Provide the most recent modification timestamp
 * - Allow forcing a manual content update timestamp
 *
 * Typical usage:
 *
 * $watcher->startTracking();
 * $watcher->addTemplate($templatePath);
 * $lastModified = $watcher->getLastModified();
 *
 * @package StreamEngine\Twig
 */
class TemplateWatcher
{
    /**
     * Collected template paths with their last modification time.
     *
     * Format:
     * [
     *     '/path/to/template.twig' => 1700000000,
     * ]
     *
     * @var array<string,int>
     */
    private array $templates = [];

    /**
     * Indicates whether template tracking is enabled.
     *
     * @var bool
     */
    private bool $trackingEnabled = false;

    /**
     * Manually forced content update timestamp.
     *
     * If greater than 0, it is included in modification comparison.
     *
     * @var int
     */
    private int $lastContentUpdate = 0;

    /**
     * Enables template tracking and resets previously collected templates.
     *
     * Should be called before rendering begins.
     *
     * @return void
     */
    public function startTracking(): void
    {
        $this->trackingEnabled = true;
        $this->templates = [];
    }

    /**
     * Adds a template file to the tracking list.
     *
     * Stores the file modification time (filemtime) for later comparison.
     * Has effect only if tracking is enabled.
     *
     * @param string $path Absolute or relative path to the template file.
     *
     * @return void
     */
    public function addTemplate(string $path): void
    {
        if ($this->trackingEnabled) {
            $this->templates[$path] = filemtime($path);
        }
    }

    /**
     * Returns the most recent modification timestamp.
     *
     * It calculates the maximum value among:
     * - All tracked template file modification times
     * - The manually forced content update timestamp (if set)
     *
     * If no templates were tracked and no forced update exists,
     * the current time is returned.
     *
     * @return int Unix timestamp of the latest modification.
     */
    public function getLastModified(): int
    {
        $updateTimes = $this->templates;
        if ($this->lastContentUpdate > 0) {
            $updateTimes[] = $this->lastContentUpdate;
        }
        return $updateTimes ? max($updateTimes) : time();
    }

    /**
     * Forces a manual content update timestamp.
     *
     * Useful when non-template content (e.g., database content)
     * affects the rendered output.
     *
     * If a timestamp is provided, it will be used.
     * Otherwise, the current time is used.
     *
     * The internal value is updated only if:
     * - It was not set before, or
     * - The new timestamp is greater than the current one.
     *
     * @param int|null $timestamp Optional Unix timestamp.
     *
     * @return void
     */
    public function forceUpdate(?int $timestamp = null): void
    {
        $updateTime = $timestamp ?? time();
        if ($this->lastContentUpdate === 0 || $this->lastContentUpdate < $updateTime) {
            $this->lastContentUpdate = $updateTime;
        }
    }
}
