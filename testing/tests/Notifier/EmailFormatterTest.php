<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Notifier;

use PHPUnit_Framework_TestCase;
use QL\Hal\Core\Entity\Application;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Deployment;
use QL\Hal\Core\Entity\Environment;
use QL\Hal\Core\Entity\Push;
use QL\Hal\Core\Entity\Server;
use QL\Hal\Core\Entity\User;
use QL\MCP\Common\Time\TimePoint;
use Twig_Environment;
use Twig_Template;
use Twig_Loader_Filesystem;

class EmailFormatterTest extends PHPUnit_Framework_TestCase
{
    public $template;

    public function setUp()
    {
        $loader = new Twig_Loader_Filesystem(__DIR__ . '/../../../configuration/templates');
        $env = new Twig_Environment($loader);
        $this->template = $env->loadTemplate('email.twig');
    }

    public function testWithoutBuildOrApplicationRendersError()
    {
        $formatter = new EmailFormatter($this->template);

        $data = [];

        $email = $formatter->format($data);

        $this->assertSame(EmailFormatter::ERR_INVALID, $email);
    }

    public function testBuildSuccess()
    {
        $formatter = new EmailFormatter($this->template);

        $app = (new Application)
            ->withId('5678')
            ->withKey('test-app')
            ->withName('Test Application')
            ->withGithubOwner('skluck')
            ->withGithubRepo('hal');

        $build = (new Build('b1234'))
            ->withUser(
                (new User('u1234'))
                    ->withHandle('testuser')
            )
            ->withStatus('Success')
            ->withBranch('master')
            ->withCommit('abcdef');

        $env = (new Environment)
            ->withName('test');

        $data = [
            'icon' => '+',
            'status' => true,
            'application' => $app,

            'build' => $build,

            'environment' => $env,
            'filesize' => [
                'download' => '9300000',
                'archive' => '14525000'
            ]
        ];

        $email = $formatter->format($data);

$expectedHeader = <<<HTML
<h2 class="alert alert--success">[+] The build succeeded</h2>
HTML;

$expectedGump = <<<HTML
<p>
    <b>testuser</b>
    created a build
    for <b>test-app</b>
    from the code at <b>master branch</b>.
</p>
HTML;

$expectedProject = <<<HTML
<h4>Which project?</h4>
<ul>
    <li><b>ID:</b> test-app</li>
    <li><b>Name:</b> Test Application</li>
    <li><b>Group:</b> Unknown</li>
</ul>
HTML;

$expectedWho = <<<HTML
<h4 style="margin:0;">Who initiated this build?</h4>
<p>testuser</p>
HTML;

$expectedEnv = <<<HTML
<h4>Which environment is the build for?</h4>
<p>test</p>
HTML;

$expectedWhat = <<<HTML
<h4>What code was built?</h4>
<ul>
    <li><b>Repository:</b> skluck/hal</li>
    <li><b>Reference:</b> master</li>
    <li><b>Commit:</b> abcdef</li>
    <li><a href="skluck/hal/tree/master">GitHub: view code</a></li>
</ul>
HTML;

$expectedFile = <<<HTML
<ul>
    <li><b>Code size:</b> 8.87 MB</li>
    <li><b>Build size:</b> 13.85 MB</li>
</ul>
HTML;

$expectedLinks = <<<HTML
<h4>Where can I see more information?</h4>
<ul>
    <li><a href="applications/5678/status">Hal: test-app status</a></li>
    <li><a href="builds/b1234">Hal: build details</a></li>
HTML;

        $this->assertContains($this->pad($expectedHeader, 16), $email);
        $this->assertContains($this->pad($expectedGump, 28), $email);
        $this->assertContains($this->pad($expectedProject, 28), $email);
        $this->assertContains($this->pad($expectedWho, 28), $email);
        $this->assertContains($this->pad($expectedEnv, 32), $email);
        $this->assertContains($this->pad($expectedWhat, 28), $email);
        $this->assertContains($this->pad($expectedFile, 32), $email);
        $this->assertContains($this->pad($expectedLinks, 28), $email);
    }

    public function testPushFailure()
    {
        $formatter = new EmailFormatter($this->template);

        $app = (new Application)
            ->withId('5678')
            ->withKey('test-app')
            ->withName('Test Application')
            ->withGithubOwner('skluck')
            ->withGithubRepo('hal');

        $build = (new Build('b1234'))
            ->withUser(
                (new User('u1234'))
                    ->withHandle('testuser')
            )
            ->withStatus('Error')
            ->withBranch('feature-branch-1')
            ->withCommit('abcdef');

        $push = (new Push('p8910'))
            ->withStatus('Error')
            ->withStart(new TimePoint(2016, 6, 10, 13, 15, 50, 'UTC'))
            ->withEnd(new TimePoint(2016, 6, 10, 13, 31, 2, 'UTC'));

        $env = (new Environment)
            ->withName('prod');

        $deployment = (new Deployment)
            ->withCDGroup('codedeploytest')
            ->withServer(
                (new Server)->withType('cd')
            );

        $data = [
            'icon' => '-',
            'status' => false,
            'application' => $app,

            'push' => $push,
            'build' => $build,

            'environment' => $env,
            'deployment' => $deployment
        ];

        $email = $formatter->format($data);

$expectedHeader = <<<HTML
<h2 class="alert alert--failure">[-] The push failed</h2>
HTML;

$expectedGump = <<<HTML
<p>
    <b>Unknown</b>
    tried to push a build
    for <b>test-app</b>
    from the code at <b>feature-branch-1 branch</b>, but it <b style="color:#C12A19;">failed</b>.
</p>
HTML;

$expectedEnv = <<<HTML
<h4>Where was the code pushed?</h4>
<ul>
    <li><b>Environment:</b> prod</li>
    <li><b>Deployment:</b> CD (codedeploytest)</li>
</ul>
HTML;

$expectedTime = <<<HTML
<h4>When was the push initiated?</h4>
<ul>
    <li><b>Start:</b> 2016-06-10 09:15:50</li>
    <li><b>End:</b> 2016-06-10 09:31:02</li>
        <li><b>Total elapsed time:</b> 15 minutes, 12 seconds</li>
HTML;

$expectedLinks = <<<HTML
<h4>Where can I see more information?</h4>
<ul>
    <li><a href="applications/5678/status">Hal: test-app status</a></li>
    <li><a href="builds/b1234">Hal: build details</a></li>
    <li><a href="pushes/p8910">Hal: push details</a></li>
HTML;

        $this->assertContains($this->pad($expectedHeader, 16), $email);
        $this->assertContains($this->pad($expectedGump, 28), $email);
        $this->assertContains($this->pad($expectedEnv, 32), $email);
        $this->assertContains($this->pad($expectedTime, 28), $email);
        $this->assertContains($this->pad($expectedLinks, 28), $email);
    }

    private function pad($content, $spaces)
    {
        $formatted = [];
        foreach (explode("\n", $content) as $line) {
            $formatted[] = str_repeat(' ', $spaces) . $line;
        }

        return implode("\n", $formatted);
    }
}
