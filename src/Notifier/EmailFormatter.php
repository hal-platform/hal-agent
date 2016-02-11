<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Notifier;

use InvalidArgumentException;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Push;
use QL\MCP\Common\Time\TimePoint;
use Twig_Template;

class EmailFormatter
{
    const ERR_INVALID = 'Error: Unable to build email notification';

    /**
     * @var Twig_Template
     */
    private $twig;

    /**
     * @param Twig_Template $twig
     */
    public function __construct(Twig_Template $twig)
    {
        $this->twig = $twig;
    }

    /**
     * @param array $data
     *
     * @return string
     */
    public function format($data)
    {
        // expected data in data:
            // icon
            // status
            // build
            // push
            // application

            // environment
            // deployment

        $icon = $this->nullable($data, 'icon');
        $status = $this->nullable($data, 'status');
        $application = $this->nullable($data, 'application');
        $build = $this->nullable($data, 'build');

        $entity = $build;
        $push = $this->nullable($data, 'push');
        if ($push instanceof Push) {
            $entity = $push;
        }

        if (!$application || !$build) {
            return self::ERR_INVALID;
        }

        // title
        if ($status !== null) {
            $type = ($entity instanceof Push) ? 'push' : 'build';
            $status = ($status === true) ? 'succeeded' : 'failed';
            $title = sprintf('[%s] The %s %s', $icon, $type, $status);
        } else {
            $type = ($entity instanceof Push) ? 'Push' : 'Build';
            $title = sprintf('[%s] %s update', $icon, $type);
        }

        $githubRepo = sprintf('%s/%s', $application->githubOwner(), $application->githubRepo());
        list($githubUrl, $githubHuman) = $this->formatGithubRef($githubRepo, $build->branch(), $build->commit());

        $context = array_merge($data, [
            'title' => $title,

            'is_success' => ($entity->status() === 'Success'),
            'is_push' => ($entity instanceof Push),

            'username' => $entity->user() ? $entity->user()->handle() : 'Unknown',

            'github' => [
                'repo' => $githubRepo,
                'ref' => $build->branch(),
                'commit' => $build->commit(),
                'human' => $githubHuman,
                'ref_url' => $githubUrl
            ],

            'filesize' => $this->findFileSizes($data),
            'time' => $this->formatTime($entity->start(), $entity->end())
        ]);

        return $this->twig->render($context);
    }

    /**
     * @param TimePoint $start
     * @param TimePoint|null $end
     *
     * @return string
     */
    private function formatTime(TimePoint $start = null, TimePoint $end = null)
    {
        if (!$start) {
            return ['start' => 'Unknown'];
        }

        $startTime = $start->format('Y-m-d H:i:s', 'America/Detroit');
        $endTime = ($end) ? $end->format('Y-m-d H:i:s', 'America/Detroit') : null;
        $elapsed = null;

        if ($endTime) {
            $diff = $start->diff($end);
            $elapsed = $diff->format('%s') . ' seconds';
            if ($minutes = $diff->format('%i')) {
                $elapsed = $minutes . ' minutes, ' . $elapsed;
            }
        }

        return [
            'start' => $startTime,
            'end' => $endTime,
            'elapsed' => $elapsed
        ];
    }

    /**
     * Attempt to find file sizes in notifier data
     *
     * @todo Add "job metadata" and store sizes there instead?
     *
     * @param array $records
     * @return array
     */
    private function findFileSizes(array $data)
    {
        $downloadSize = null;
        $archiveSize = null;
        $tooBig = false;

        if (array_key_exists('filesize', $data)) {
            if (isset($data['filesize']['download'])) {
                $downloadSize = sprintf('%s MB', round($data['filesize']['download'] / 1048576, 2));
            }

            if (isset($data['filesize']['archive'])) {
                $size = round($data['filesize']['archive'] / 1048576, 2);
                $archiveSize = sprintf('%s MB', $size);
                $tooBig = $size > 50;
            }
        }

        return [
            'download' => $downloadSize,
            'archive' => $archiveSize,
            'tooBig' => $tooBig
        ];
    }

    /**
     * @return string $repo
     * @return string $branch
     * @return string $commit
     *
     * @return array
     */
    private function formatGithubRef($repo, $branch, $commit)
    {
        $base = $repo;

        // commit
        if ($branch === $commit) {
            return [
                $base . '/commit/' . $commit,
                'commit ' . $commit
            ];
        }

        // pull request
        if (substr($branch, 0, 5) === 'pull/') {
            return [
                $base . '/' . $branch,
                'pull request #' . substr($branch, 5)
            ];
        }

        // pull request
        if (substr($branch, 0, 4) === 'tag/') {
            return [
                $base . '/releases/' . $branch,
                'release ' . substr($branch, 4)
            ];
        }

        // branch
        return [
            $base . '/tree/' . $branch,
            $branch . ' branch'
        ];
    }

    /**
     * @param array $data
     * @param string $key
     *
     * @return mixed
     */
    private function nullable(array $data, $key)
    {
        if (isset($data[$key])) {
            return $data[$key];
        }

        return null;
    }
}
