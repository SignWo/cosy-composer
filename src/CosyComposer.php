<?php

namespace eiriksm\CosyComposer;

use Composer\Console\Application;
use Composer\Semver\Semver;
use eiriksm\CosyComposer\Exceptions\CanNotUpdateException;
use eiriksm\CosyComposer\Exceptions\ChdirException;
use eiriksm\CosyComposer\Exceptions\ComposerInstallException;
use eiriksm\CosyComposer\Exceptions\GitCloneException;
use eiriksm\CosyComposer\Exceptions\GitPushException;
use eiriksm\CosyComposer\Exceptions\NotUpdatedException;
use eiriksm\GitLogFormat\ChangeLogData;
use eiriksm\ViolinistMessages\ViolinistMessages;
use eiriksm\ViolinistMessages\ViolinistUpdate;
use Github\Client;
use Github\Exception\RuntimeException;
use Github\Exception\ValidationFailedException;
use Github\HttpClient\Builder;
use Github\ResultPager;
use League\Flysystem\Adapter\Local;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Violinist\Slug\Slug;

class CosyComposer
{
    /**
     * @return LoggerInterface
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger($logger)
    {
        $this->logger = $logger;
    }

    /**
     * @var LoggerInterface
     */
    protected $logger;

  /**
   * @var array
   */
    private $messages = [];

  /**
   * @return string
   */
    public function getCacheDir()
    {
        return $this->cacheDir;
    }

  /**
   * @param string $cacheDir
   */
    public function setCacheDir($cacheDir)
    {
        $this->cacheDir = $cacheDir;
    }

  /**
   * @var string
   */
    private $cacheDir = '/tmp';


  /**
   * @var string
   */
    protected $tmpDir;

  /**
   * @return string
   */
    public function getTmpParent()
    {
        return $this->tmpParent;
    }

  /**
   * @param string $tmpParent
   */
    public function setTmpParent($tmpParent)
    {
        $this->tmpParent = $tmpParent;
    }

  /**
   * @var string
   */
    protected $tmpParent = '/tmp';

    /**
     * @var Application
     */
    private $app;

    /**
     * @return Application
     */
    public function getApp(): Application
    {
        return $this->app;
    }

    /**
     * @param Application $app
     */
    public function setApp(Application $app)
    {
        $this->app = $app;
    }

    /**
     * The output we use for updates?
     *
     * @var OutputInterface
     */
    protected $output;

  /**
   * @return string
   */
    public function getCwd()
    {
        return $this->cwd;
    }

  /**
   * @var string
   */
    protected $cwd;

  /**
   * @var string
   */
    private $token;

  /**
   * @var string
   */
    private $slug;

  /**
   * @var string
   */
    private $githubUser;

  /**
   * @var string
   */
    private $githubPass;

  /**
   * @var string
   */
    private $forkUser;

  /**
   * @var mixed
   */
    private $proc_open = 'proc_open';

    private $proc_close = 'proc_close';

    private $pipes = [];

    private $contentGetter = 'stream_get_contents';

    private $githubUserName;
    private $githubUserPass;
    private $githubEmail;
    private $messageFactory;

  /**
   * @var string
   */
    private $lastStdErr = '';

  /**
   * @var string
   */
    private $lastStdOut = '';

  /**
   * @return string
   */
    public function getLastStdErr()
    {
        return $this->lastStdErr;
    }

  /**
   * @param string $lastStdErr
   */
    public function setLastStdErr($lastStdErr)
    {
        $this->lastStdErr = $lastStdErr;
    }

  /**
   * @return string
   */
    public function getLastStdOut()
    {
        return $this->lastStdOut;
    }

  /**
   * @param string $lastStdOut
   */
    public function setLastStdOut($lastStdOut)
    {
        $this->lastStdOut = $lastStdOut;
    }

    /**
     * @var \eiriksm\CosyComposer\CommandExecuter
     */
    protected $executer;

    /**
     * @var ComposerFileGetter
     */
    protected $composerGetter;

    /**
     * @return \eiriksm\CosyComposer\CommandExecuter
     */
    public function getExecuter()
    {
        return $this->executer;
    }

    /**
     * @param \eiriksm\CosyComposer\CommandExecuter $executer
     */
    public function setExecuter($executer)
    {
        $this->executer = $executer;
    }

    /**
     * @return ProviderFactory
     */
    public function getProviderFactory()
    {
        return $this->providerFactory;
    }

    /**
     * @param ProviderFactory $providerFactory
     */
    public function setProviderFactory(ProviderFactory $providerFactory)
    {
        $this->providerFactory = $providerFactory;
    }

    /**
     * @var ProviderFactory
     */
    protected $providerFactory;

  /**
   * CosyComposer constructor.
   * @param string $token
   * @param string $slug
   */
    public function __construct($token, $slug, Application $app, OutputInterface $output, CommandExecuter $executer)
    {
        $this->token = $token;
        // @todo: Move to create from URL.
        $this->slug = new Slug();
        $this->slug->setProvider('github.com');
        $this->slug->setSlug($slug);
        $tmpdir = uniqid();
        $this->tmpDir = sprintf('/tmp/%s', $tmpdir);
        $this->messageFactory = new ViolinistMessages();
        $this->app = $app;
        $this->output = $output;
        $this->executer = $executer;
    }

    public function setGithubAuth($user, $pass)
    {
        $this->githubUser = $user;
        $this->forkUser = $user;
        $this->githubPass = $pass;
    }

    public function setGithubForkAuth($user, $pass, $mail)
    {
        $this->githubUserName = $user;
        $this->githubUserPass = $pass;
        $this->githubEmail = $mail;
    }

  /**
   * Set a user to fork to.
   *
   * @param string $user
   */
    public function setForkUser($user)
    {
        $this->forkUser = $user;
    }

  /**
   * @throws \eiriksm\CosyComposer\Exceptions\ChdirException
   * @throws \eiriksm\CosyComposer\Exceptions\GitCloneException
   * @throws \InvalidArgumentException
   * @throws \Exception
   */
    public function run()
    {
        // Export the user token so composer can use it.
        $this->execCommand(
            sprintf('COMPOSER_ALLOW_SUPERUSER=1 composer config --auth github-oauth.github.com %s', $this->githubUser),
            false
        );
        $this->log(sprintf('Starting update check for %s', $this->slug->getSlug()));
        $user_name = $this->slug->getUserName();
        $user_repo = $this->slug->getUserRepo();
        // First set working dir to /tmp (since we might be in the directory of the
        // last processed item, which may be deleted.
        if (!$this->chdir($this->getTmpParent())) {
            throw new ChdirException('Problem with changing dir to ' . $this->getTmpParent());
        }
        $url = sprintf('https://%s:%s@github.com/%s', $this->githubUser, $this->githubPass, $this->slug->getSlug());
        $this->log('Cloning repository');
        $clone_result = $this->execCommand('git clone --depth=1 ' . $url . ' ' . $this->tmpDir, false, 120);
        if ($clone_result) {
            // We had a problem.
            throw new GitCloneException('Problem with the execCommand git clone. Exit code was ' . $clone_result);
        }
        $this->log('Repository cloned');
        if (!$this->chdir($this->tmpDir)) {
            throw new ChdirException('Problem with changing dir to the clone dir.');
        }
        $local_adapter = new Local($this->tmpDir);
        $this->composerGetter = new ComposerFileGetter($local_adapter);
        if (!$this->composerGetter->hasComposerFile()) {
            throw new \InvalidArgumentException('No composer.json file found.');
        }
        $cdata = $this->composerGetter->getComposerJsonData();
        if (false == $cdata) {
            throw new \InvalidArgumentException('Invalid composer.json file');
        }
        $lock_file = $this->tmpDir . '/composer.lock';
        $lock_file_contents = false;
        if (@file_exists($lock_file)) {
            // We might want to know whats in here.
            $lock_file_contents = json_decode(file_get_contents($lock_file));
        }
        $app = $this->app;
        $d = $app->getDefinition();
        $opts = $d->getOptions();
        $opts['no-ansi'] = new InputOption('no-ansi', null, 4, true, 'Disable ANSI output');
        $d->setOptions($opts);
        $app->setDefinition($d);
        $app->setAutoExit(false);
        $this->doComposerInstall();
        $i = new ArrayInput([
            'outdated',
            '-d' => $this->getCwd(),
            '--direct' => true,
            '--minor-only' => true,
            '--format' => 'json',
        ]);
        $app->run($i, $this->output);
        $raw_data = $this->output->fetch();
        foreach ($raw_data as $delta => $item) {
            if (empty($item) || empty($item[0])) {
                continue;
            }
            if (!is_array($item)) {
                // Can't be it.
                continue;
            }
            foreach ($item as $value) {
                if (!$json_update = @json_decode($value)) {
                    // Not interesting.
                    continue;
                }
                if (!isset($json_update->installed)) {
                    throw new \Exception(
                        'JSON output from composer was not looking as expected after checking updates'
                    );
                }
                $data = $json_update->installed;
                break;
            }
        }
        if (empty($data)) {
            $this->log('No updates found');
            $this->cleanup();
            return;
        }
        // Try to log what updates are found.
        $updates_string = '';
        foreach ($data as $delta => $item) {
            $updates_string .= sprintf(
                "%s: %s installed, %s available (type %s)\n",
                $item->name,
                $item->version,
                $item->latest,
                $item->{'latest-status'}
            );
        }
        $this->log($updates_string, Message::UPDATE);
        $client = $this->getClient($this->slug);
        $client->authenticate($this->token, null);
        // Get the default branch of the repo.
        $private_client = $this->getClient($this->slug);
        $private_client->authenticate($this->githubUser, null);
        $private = $private_client->repoIsPrivate($user_name, $user_repo);
        $default_branch = $private_client->getDefaultBranch($user_name, $user_repo);
        // Try to see if we have already dealt with this (i.e already have a branch for all the updates.
        $pr_client = $client;
        $branch_user = $this->forkUser;
        if ($private) {
            $pr_client = $private_client;
            $branch_user = $user_name;
        }
        try {
            $branches_flattened = $pr_client->getBranchesFlattened($branch_user, $user_repo);
            $default_base = $pr_client->getDefaultBase($branch_user, $user_repo, $default_branch);
            if ($default_base_upstream = $private_client->getDefaultBase($user_name, $user_repo, $default_branch)) {
                $default_base = $default_base_upstream;
            }
            $prs_named = $private_client->getPrsNamed($user_name, $user_repo);
        } catch (RuntimeException $e) {
            // Safe to ignore.
            $this->log('Had a runtime exception with the fetching of branches and Prs: ' . $e->getMessage());
        }
        foreach ($data as $delta => $item) {
            $branch_name = $this->createBranchName($item);
            if (in_array($branch_name, $branches_flattened)) {
              // Is there a PR for this?
                if (array_key_exists($branch_name, $prs_named)) {
                    if (!$default_base) {
                        unset($data[$delta]);
                    }
                  // Is the pr up to date?
                    if ($prs_named[$branch_name]['base']['sha'] == $default_base) {
                        unset($data[$delta]);
                    }
                }
            }
        }
        if (empty($data)) {
            $this->log('No updates that have not already been pushed.');
            $this->cleanup();
            return;
        }

        // Unshallow the repo, for syncing it.
        $this->execCommand('git pull --unshallow', false, 300);
        // If the repo is private, we need to push directly to the repo.
        if (!$private) {
            $fork = $client->createFork($user_name, $user_repo, $this->forkUser);
            $fork_url = sprintf('https://%s:%s@github.com/%s/%s', $this->githubUserName, $this->githubUserPass, $this->forkUser, $user_repo);
            $this->execCommand('git remote add fork ' . $fork_url, false);
            // Sync the fork.
            $this->execCommand('git push fork ' . $default_branch, false);
        }
        // Now read the lockfile.
        $lockdata = json_decode(file_get_contents($this->tmpDir . '/composer.lock'));
        foreach ($data as $item) {
            try {
                $package_name = $item->name;
                $pre_update_data = $this->getPackageData($package_name, $lockdata);
                $version_from = $item->version;
                $version_to = $item->latest;
                // First see if we can update this at all?
                // @todo: Just logging this for now, but this would be nice to have.
                $this->execCommand(sprintf('COMPOSER_ALLOW_SUPERUSER=1 composer --no-ansi why-not -t %s:%s', $package_name, $version_to), true, 300);
                // See where this package is.
                $req_command = 'require';
                $lockfile_key = 'require';
                if (!empty($cdata->{'require-dev'}->{$package_name})) {
                    $lockfile_key = 'require-dev';
                    $req_command = 'require --dev';
                    $req_item = $cdata->{'require-dev'}->{$package_name};
                } else {
                    $req_item = $cdata->{'require'}->{$package_name};
                }
                // See if the new version seems to satisfy the constraint.
                if (!Semver::satisfies($version_to, (string) $req_item)) {
                    throw new CanNotUpdateException(sprintf('Package %s with the constraint %s can not be updated to %s', $package_name, $req_item, $version_to));
                }
                // Create a new branch.
                $branch_name = $this->createBranchName($item);
                $this->log('Checking out new branch: ' . $branch_name);
                $this->execCommand('git checkout -b ' . $branch_name, false);
                // Make sure we do not have any uncommitted changes.
                $this->execCommand('git checkout .', false);
                // Try to use the same version constraint.
                $version = (string) $req_item;
                switch ($version[0]) {
                    case '^':
                        $constraint = '^';
                        break;

                    case '~':
                        $constraint = '~';
                        break;

                    default:
                        $constraint = '';
                        break;
                }
                if (!$lock_file_contents) {
                    $command = sprintf('composer --no-ansi %s %s:%s%s', $req_command, $package_name, $constraint, $version_to);
                    $this->execCommand($command, false, 600);
                } else {
                    $command = 'COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_DISCARD_CHANGES=true composer --no-ansi update -n --no-scripts --with-dependencies ' . $package_name;
                    $this->log('Running composer update for package ' . $package_name);
                    // If exit code is not 0, there was a problem.
                    if ($this->execCommand($command, false, 600)) {
                        $this->log('Problem running composer update:');
                        $this->log($this->lastStdErr);
                        throw new \Exception('Composer update did not complete successfully');
                    }
                    $this->log('Successfully ran command composer update for package ' . $package_name);
                    // If the constraint is empty, we also try to require the new version.
                    if ($constraint == '' && strpos($version, 'dev') === false) {
                        // @todo: Duplication from like 6 lines earlier.
                        $command = sprintf('COMPOSER_ALLOW_SUPERUSER=1 composer --no-ansi %s %s:%s%s --update-with-dependencies', $req_command, $package_name, $constraint, $version_to);
                        $this->execCommand($command, false, 600);
                    }
                }
                // Clean away the lock file if we are not supposed to use it. But first
                // read it for use later.
                $new_lockdata = json_decode(file_get_contents($this->tmpDir . '/composer.lock'));
                $post_update_data = $this->getPackageData($package_name, $new_lockdata);
                if (isset($post_update_data->source) || $post_update_data->source->type == 'git') {
                    $version_from = $pre_update_data->source->reference;
                    $version_to = $post_update_data->source->reference;
                }
                if ($version_to === $version_from) {
                  // Nothing has happened here. Although that can be alright (like we
                  // have updated some dependencies of this package) this is not what
                  // this service does, currently, and also the title of the PR would be
                  // wrong.
                    throw new NotUpdatedException('The version installed is still the same after trying to update.');
                }
                $this->execCommand('git clean -f composer.*');
              // This might have cleaned out the auth file, so we re-export it.
                $this->execCommand(sprintf('COMPOSER_ALLOW_SUPERUSER=1 composer config --auth github-oauth.github.com %s', $this->githubUser));
                $command = sprintf(
                    'GIT_AUTHOR_NAME="%s" GIT_AUTHOR_EMAIL="%s" GIT_COMMITTER_NAME="%s" GIT_COMMITTER_EMAIL="%s" git commit composer.* -m "Update %s"',
                    $this->githubUserName,
                    $this->githubEmail,
                    $this->githubUserName,
                    $this->githubEmail,
                    $package_name
                );
                if ($this->execCommand($command, false)) {
                    throw new \Exception('Error committing the composer files. They are probably not changed.');
                }
                $origin = 'fork';
                if ($private) {
                    $origin = 'origin';
                }
                if ($this->execCommand("git push $origin $branch_name --force")) {
                    throw new GitPushException('Could not push to ' . $branch_name);
                }
                $this->log('Trying to retrieve changelog for ' . $package_name);
                $changelog = null;
                try {
                    $changelog = $this->retrieveChangeLog($package_name, $lockdata, $version_from, $version_to);
                    $this->log('Changelog retrieved');
                } catch (\Exception $e) {
                    // New feature. Just log it.
                    $this->log('Exception for changelog: ' . $e->getMessage());
                }
                $this->log('Creating pull request from ' . $branch_name);
                $head = $this->forkUser . ':' . $branch_name;
                if ($private) {
                    $head = $branch_name;
                }
                $body = $this->createBody($item, $changelog);
                $pullRequest = $pr_client->createPullRequest($user_name, $user_repo, [
                    'base'  => $default_branch,
                    'head'  => $head,
                    'title' => $this->createTitle($item),
                    'body'  => $body,
                ]);
                if (!empty($pullRequest['html_url'])) {
                    $this->log($pullRequest['html_url'], Message::PR_URL);
                }
            } catch (CanNotUpdateException $e) {
                $this->log($e->getMessage(), 'error');
            } catch (ValidationFailedException $e) {
              // @todo: Do some better checking. Could be several things, this.
                $this->log('Had a problem with creating the pull request: ' . $e->getMessage(), 'error');
            } catch (\Exception $e) {
              // @todo: Should probably handle this in some way.
                $this->log('Caught an exception: ' . $e->getMessage(), 'error');
            }
            $this->log('Checking out default branch - ' . $default_branch);
            $this->execCommand('git checkout ' . $default_branch, false);
        }
      // Clean up.
        $this->cleanUp();
    }

  /**
   * Get the messages that are logged.
   *
   * @return \eiriksm\CosyComposer\Message[]
   *   The logged messages.
   */
    public function getOutput()
    {
        $msgs = [];
        foreach ($this->logger->get() as $message) {
            $msgs[] = $message['message'];
        }
        return $msgs;
    }

    /**
     * @param OutputInterface $output
     */
    public function setOutput(OutputInterface $output)
    {
        $this->output = $output;
    }

  /**
   * Cleans up after the run.
   */
    private function cleanUp()
    {
        $this->chdir('/tmp');
        $this->log('Cleaning up after update check.');
        $this->log('Storing custom composer cache for later');
        $this->execCommand(
            sprintf(
                'rsync -az --exclude "composer.*" %s/* %s',
                $this->tmpDir,
                $this->createCacheDir()
            ),
            false,
            300
        );
        $this->execCommand('rm -rf ' . $this->tmpDir, false, 300);
    }

  /**
   * Returns the cache directory, and creates it if necessary.
   *
   * @return string
   */
    public function createCacheDir()
    {
        $dir_name = md5($this->slug->getSlug());
        $path = sprintf('%s/%s', $this->cacheDir, $dir_name);
        if (!file_exists($path)) {
            mkdir($path, 0777, true);
        }
        return $path;
    }

  /**
   * Creates a title for a PR.
   *
   * @param object $item
   *   The item in question.
   *
   * @return string
   *   A string ready to use.
   */
    protected function createTitle($item)
    {
        $update = new ViolinistUpdate();
        $update->setName($item->name);
        $update->setCurrentVersion($item->version);
        $update->setNewVersion($item->latest);
        return $this->messageFactory->getPullRequestTitle($update);
    }

  /**
   * @param $item
   *
   * @return string
   */
    public function createBody($item, $changelog = null)
    {
        $update = new ViolinistUpdate();
        $update->setName($item->name);
        $update->setCurrentVersion($item->version);
        $update->setNewVersion($item->latest);
        if ($changelog) {
          /** @var \eiriksm\GitLogFormat\ChangeLogData $changelog */
            $update->setChangelog($changelog->getAsMarkdown());
        }
        return $this->messageFactory->getPullRequestBody($update);
    }

  /**
   * @param $item
   *
   * @return mixed
   */
    protected function createBranchName($item)
    {
        $item_string = sprintf('%s%s%s', $item->name, $item->version, $item->latest);
        // @todo: Fix this properly.
        $result = preg_replace('/[^a-zA-Z0-9]+/', '', $item_string);
        return $result;
    }

  /**
   * Executes a command.
   */
    protected function execCommand($command, $log = true, $timeout = 120)
    {
        $this->executer->setCwd($this->getCwd());
        return $this->executer->executeCommand($command, $log, $timeout);
    }

  /**
   * Sets the function to call for getting the contents.
   *
   * @param $callable
   *   A callable function.
   */
    public function setContentGetter($callable)
    {
        $this->contentGetter = $callable;
    }

    public function setPipes(array $pipes)
    {
        $this->pipes = $pipes;
    }

    public function getPipes()
    {
        return $this->pipes;
    }

  /**
   * @param string $proc_close
   */
    public function setProcClose($proc_close)
    {
        $this->proc_close = $proc_close;
    }

  /**
   * @param string $proc_open
   */
    public function setProcOpen($proc_open)
    {
        $this->proc_open = $proc_open;
    }

  /**
   * Log a message.
   *
   * @param string $message
   */
    protected function log($message, $type = 'message')
    {
        $this->logger->log('info', new Message($message, $type));
    }

  /**
   * Does a composer install.
   *
   * @throws \eiriksm\CosyComposer\Exceptions\ComposerInstallException
   */
    protected function doComposerInstall()
    {
      // First copy the custom cache in here.
        if (file_exists($this->createCacheDir())) {
            $this->log('Found custom cache. using this for vendor folder.');
            $this->execCommand(sprintf('rsync -a %s/* %s/', $this->createCacheDir(), $this->tmpDir), false, 300);
        }
        // @todo: Should probably use composer install command programatically.
        $this->log('Running composer install');
        if ($code = $this->execCommand('COMPOSER_ALLOW_SUPERUSER=1 composer install --no-ansi -n --no-scripts', false, 1200)) {
            // Other status code than 0.
            $this->messages[] = new Message($this->getLastStdOut(), 'stdout');
            $this->messages[] = new Message($this->getLastStdErr(), 'stderr');
            throw new ComposerInstallException('Composer install failed with exit code ' . $code);
        }
        $this->log('composer install completed successfully');
    }

  /**
   * Changes to a different directory.
   */
    private function chdir($dir)
    {
        if (!file_exists($dir)) {
            return false;
        }
        $this->setCWD($dir);
        return true;
    }

    protected function setCWD($dir)
    {
        $this->cwd = $dir;
    }


    /**
     * @return string
     */
    public function getTmpDir()
    {
        return $this->tmpDir;
    }

    /**
     * @param $tmpDir
     */
    public function setTmpDir($tmpDir)
    {
        $this->tmpDir = $tmpDir;
    }

    /**
     * @param $package_name
     * @param $lockdata
     * @param $version_from
     * @param $version_to
     * @return ChangeLogData
     * @throws \Exception
     */
    public function retrieveChangeLog($package_name, $lockdata, $version_from, $version_to)
    {
        $data = $this->getPackageData($package_name, $lockdata);
        $clone_path = $this->retrieveDependencyRepo($data);
      // Then try to get the changelog.
        $command = sprintf('git -C %s log %s..%s --oneline', $clone_path, $version_from, $version_to);
        $this->execCommand($command, false);
        $changelog_string = $this->getLastStdOut();
        if (empty($changelog_string)) {
            throw new \Exception('The changelog string was empty');
        }
      // If the changelog is too long, truncate it.
        if (mb_strlen($changelog_string) > 60000) {
          // Truncate it to 60K.
            $changelog_string = mb_substr($changelog_string, 0, 60000);
          // Then split it into lines.
            $lines = explode("\n", $changelog_string);
          // Cut off the last one, since it could be partial.
            array_pop($lines);
          // Then append a line saying the changelog was too long.
            $lines[] = sprintf('%s ...more commits found, but message is too long for PR', $version_to);
            $changelog_string = implode("\n", $lines);
        }
      // Then split it into lines that makes sense.
        $log = ChangeLogData::createFromString($changelog_string);
      // Then assemble the git source.
        $git_url = preg_replace('/.git$/', '', $data->source->url);
        $log->setGitSource($git_url);
        return $log;
    }

    private function retrieveDependencyRepo($data)
    {
        // First find the repo source.
        if (!isset($data->source) || $data->source->type != 'git') {
            throw new \Exception('Unknown source or non-git source. Aborting.');
        }
        // We could have this cached in the md5 of the package name.
        $clone_path = '/tmp/' . md5($data->name);
        $repo_path = $data->source->url;
        if (!file_exists($clone_path)) {
            $this->execCommand(sprintf('git clone %s %s', $repo_path, $clone_path), false, 300);
        } else {
            $this->execCommand(sprintf('git -C %s pull', $clone_path), false, 300);
        }
        return $clone_path;
    }

    private function getPackageData($package_name, $lockdata)
    {
        $lockfile_key = 'packages';
        $key = $this->getPackagesKey($package_name, $lockfile_key, $lockdata);
        if ($key === false) {
            // Well, could be a dev req.
            $lockfile_key = 'packages-dev';
            $key = $this->getPackagesKey($package_name, $lockfile_key, $lockdata);
            // If the key still is false, then this is not looking so good.
            if ($key === false) {
                throw new \Exception(
                    sprintf(
                        'Did not find the requested package (%s) in the lockfile. This is probably an error',
                        $package_name
                    )
                );
            }
        }
        return $lockdata->{$lockfile_key}[$key];
    }

    private function getPackagesKey($package_name, $lockfile_key, $lockdata)
    {
        $names = array_column($lockdata->{$lockfile_key}, 'name');
        return array_search($package_name, $names);
    }

    /**
     * @param Slug $slug
     *
     * @return ProviderInterface
     */
    private function getClient(Slug $slug)
    {
        if (!$this->providerFactory) {
            $this->setProviderFactory(new ProviderFactory());
        }
        return $this->providerFactory->createFromHost($slug);
    }
}
