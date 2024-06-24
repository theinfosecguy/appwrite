<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Migration\Migration;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Http\Validator\Text;
use Utopia\Platform\Action;
use Utopia\Registry\Registry;

class Migrate extends Action
{
    public static function getName(): string
    {
        return 'migrate';
    }

    public function __construct()
    {
        $this
            ->desc('Migrate Appwrite to new version')
            /** @TODO APP_VERSION_STABLE needs to be defined */
            ->param('version', APP_VERSION_STABLE, new Text(8), 'Version to migrate to.', true)
            ->inject('cache')
            ->inject('dbForConsole')
            ->inject('getProjectDB')
            ->inject('register')
            ->inject('authorization')
            ->inject('console')
            ->callback(fn ($version, $cache, $dbForConsole, $getProjectDB, Registry $register, Authorization $authorization, Document $console) => $this->action($version, $cache, $dbForConsole, $getProjectDB, $register, $authorization, $console));
    }

    private function clearProjectsCache(Cache $cache, Document $project)
    {
        try {
            $cache->purge("cache-_{$project->getInternalId()}:*");
        } catch (\Throwable $th) {
            Console::error('Failed to clear project ("' . $project->getId() . '") cache with error: ' . $th->getMessage());
        }
    }

    public function action(string $version, Cache $cache, Database $dbForConsole, callable $getProjectDB, Registry $register, Authorization $auth, Document $console)
    {
        $auth->disable();
        if (!array_key_exists($version, Migration::$versions)) {
            Console::error("Version {$version} not found.");
            Console::exit(1);
            return;
        }


        Console::success('Starting Data Migration to version ' . $version);

        $limit = 30;
        $sum = 30;
        $offset = 0;
        /**
         * @var \Utopia\Database\Document[] $projects
         */
        $projects = [$console];
        $count = 0;

        try {
            $totalProjects = $dbForConsole->count('projects') + 1;
        } catch (\Throwable $th) {
            $dbForConsole->setNamespace('_console');
            $totalProjects = $dbForConsole->count('projects') + 1;
        }

        $class = 'Appwrite\\Migration\\Version\\' . Migration::$versions[$version];
        /** @var Migration $migration */
        $migration = new $class($auth, );

        while (!empty($projects)) {
            foreach ($projects as $project) {
                /**
                 * Skip user projects with id 'console'
                 */
                if ($project->getId() === 'console' && $project->getInternalId() !== 'console') {
                    continue;
                }

                $this->clearProjectsCache($cache, $project);

                try {
                    // TODO: Iterate through all project DBs
                    /** @var Database $projectDB */
                    $projectDB = $getProjectDB($project);
                    $migration
                        ->setProject($project, $projectDB, $dbForConsole)
                        ->setPDO($register->get('db', true))
                        ->execute();
                } catch (\Throwable $th) {
                    Console::error('Failed to update project ("' . $project->getId() . '") version with error: ' . $th->getMessage());
                    throw $th;
                }

                $this->clearProjectsCache($cache, $project);
            }

            $sum = \count($projects);
            $projects = $dbForConsole->find('projects', [Query::limit($limit), Query::offset($offset)]);

            $offset = $offset + $limit;
            $count = $count + $sum;

            Console::log('Migrated ' . $count . '/' . $totalProjects . ' projects...');
        }

        Console::success('Data Migration Completed');
    }
}
