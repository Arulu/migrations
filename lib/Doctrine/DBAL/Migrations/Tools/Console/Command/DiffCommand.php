<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the LGPL. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\DBAL\Migrations\Tools\Console\Command;

use Symfony\Component\Console\Input\InputInterface,
    Symfony\Component\Console\Output\OutputInterface,
    Symfony\Component\Console\Input\InputArgument,
    Symfony\Component\Console\Input\InputOption,
    Doctrine\ORM\Tools\SchemaTool,
    Doctrine\DBAL\Migrations\Configuration\Configuration,
	Doctrine\DBAL\Connection;

/**
 * Command for generate migration classes by comparing your current database schema
 * to your mapping information.
 *
 * @license http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link    www.doctrine-project.org
 * @since   2.0
 * @author  Jonathan Wage <jonwage@gmail.com>
 */
class DiffCommand extends GenerateCommand
{
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('migrations:diff')
            ->setDescription('Generate a migration by comparing your current database to your mapping information.')
			->addOption("database", "d", InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED)
            ->setHelp(<<<EOT
The <info>%command.name%</info> command generates a migration by comparing your current database to your mapping information:

    <info>%command.full_name%</info>

You can optionally specify a <comment>--editor-cmd</comment> option to open the generated file in your favorite editor:

    <info>%command.full_name% --editor-cmd=mate</info>
EOT
            )
            ->addOption('filter-expression', null, InputOption::VALUE_OPTIONAL, 'Tables which are filtered by Regular Expression.');
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $configuration = $this->getMigrationConfiguration($input, $output);
		$databases = $input->getOption("database");

        $em = $this->getHelper('em')->getEntityManager();

		$up = "";
		$down = "";

		foreach($em->getConfiguration()->getConnections() as $connection)
		{
			if(!in_array($connection->getDatabase(), $databases))
				continue;

			$output->writeln('Processing database <info>' .$connection->getDatabase(). '</info>');

			// hacked this to change entityManager connection
			$entityManager = new \ReflectionClass(get_class($em));
			$entityManager = $entityManager->getParentClass();

			$property = $entityManager->getProperty('conn');

			$property->setAccessible(true);
			$property->setValue($em, $connection);


			$metadata = $em->getMetadataFactory()->getMetadataForConnection($connection);

			$conn = $connection;
			$platform = $conn->getDatabasePlatform();

			if (empty($metadata)) {
				$output->writeln('No mapping information to process.', 'ERROR');
				return;
			}

			$tool = new SchemaTool($em);

			$fromSchema = $conn->getSchemaManager()->createSchema();
			$toSchema = $tool->getSchemaFromMetadata($metadata);

			$up .= $this->buildCodeFromSql($conn, $configuration, $fromSchema->getMigrateToSql($toSchema, $platform));
			$down .= $this->buildCodeFromSql($conn, $configuration, $fromSchema->getMigrateFromSql($toSchema, $platform));

			unset($entityManager);

		}

		if (empty($up) && empty($down)) {
			$output->writeln('No changes detected in your mapping information.', 'ERROR');
			return;
		}

		$version = date('YmdHis');
		$path = $this->generateMigration($configuration, $input, $version, $up, $down);

		$output->writeln(sprintf('Generated new migration class to "<info>%s</info>" from schema differences.', $path));
    }

    private function buildCodeFromSql(Connection $connection, Configuration $configuration, array $sql)
    {
		$code = array();

        foreach ($sql as $query) {
            if (strpos($query, $configuration->getMigrationsTableName()) !== false) {
                continue;
            }
            $code[] = "\$this->addSql(\"$query\");";
        }
        return implode("\n", $code);
    }
}
