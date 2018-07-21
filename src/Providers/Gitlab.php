<?php

namespace eiriksm\CosyComposer\Providers;

use eiriksm\CosyComposer\ProviderInterface;
use Gitlab\Client;
use Gitlab\ResultPager;

class Gitlab implements ProviderInterface
{

    protected $client;

    private $cache;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function authenticate($user, $token)
    {
        $this->client->authenticate($user,  Client::AUTH_OAUTH_TOKEN);
    }

    public function authenticatePrivate($user, $token)
    {
        $this->client->authenticate($user, Client::AUTH_OAUTH_TOKEN);
    }

    public function repoIsPrivate($user, $repo)
    {
        // Consider all gitlab things private, since we have the API key to do so anyway-
        return TRUE;
    }

    public function getDefaultBranch($user, $repo)
    {
        if (!isset($this->cache['repo'])) {
            $this->cache['repo'] = $this->client->api('projects')->show($this->getProjectId($user, $repo));
        }
        return $this->cache['repo']['default_branch'];
    }

    protected function getBranches($user, $repo)
    {
        if (!isset($this->cache['branches'])) {
            $pager = new ResultPager($this->client);
            $api = $this->client->api('repo');
            $method = 'branches';
            $this->cache['branches'] = $pager->fetchAll($api, $method, [$this->getProjectId($user, $repo)]);
        }
        return $this->cache['branches'];
    }

    public function getBranchesFlattened($user, $repo)
    {
        $branches = $this->getBranches($user, $repo);

        $branches_flattened = [];
        foreach ($branches as $branch) {
            $branches_flattened[] = $branch['name'];
        }
        return $branches_flattened;
    }

    public function getPrsNamed($user, $repo)
    {
        $pager = new ResultPager($this->client);
        $api = $this->client->api('mr');
        $method = 'all';
        $prs = $pager->fetchAll($api, $method, [$this->getProjectId($user, $repo)]);
        $prs_named = [];
        foreach ($prs as $pr) {
            $prs_named[$pr['head']['ref']] = $pr;
        }
        return $prs_named;
    }

    public function getDefaultBase($user, $repo, $default_branch)
    {
        $branches = $this->getBranches($user, $repo);
        $default_base = null;
        foreach ($branches as $branch) {
            if ($branch['name'] == $default_branch) {
                $default_base = $branch['commit']['id'];
            }
        }
        return $default_base;
    }

    public function createFork($user, $repo, $fork_user)
    {
        throw new \Exception('Gitlab integration only support creating PRs as the authenticated user.');
    }

    public function createPullRequest($user_name, $user_repo, $params)
    {
        return $this->client->api('mr')->create($this->getProjectId($user_name, $user_repo), $params['head'], $params['base'], $params['title'], null, null, $params['body']);
    }

    protected function getProjectId($user, $repo)
    {
        return sprintf('%s/%s', $user, $repo);
    }
}
