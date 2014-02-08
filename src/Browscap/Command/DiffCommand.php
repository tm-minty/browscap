<?php

namespace Browscap\Command;

use Browscap\Generator\BrowscapIniGenerator;
use Browscap\Generator\CollectionParser;
use Browscap\Helper\CollectionCreator;
use Browscap\Parser\IniParser;
use Monolog\ErrorHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author James Titcumb <james@asgrim.com>
 */
class DiffCommand extends Command
{
    /**
     * @var int Number of differences found in total
     */
    protected $diffsFound;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger = null;

    /**
     * (non-PHPdoc)
     * @see \Symfony\Component\Console\Command\Command::configure()
     */
    protected function configure()
    {
        $this
            ->setName('diff')
            ->setDescription('Compare the data contained within two .ini files (regardless of order or format)')
            ->addArgument('left', InputArgument::REQUIRED, 'The left .ini file to compare')
            ->addArgument('right', InputArgument::OPTIONAL, 'The right .ini file to compare');
    }

    /**
     * (non-PHPdoc)
     * @see \Symfony\Component\Console\Command\Command::execute()
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->diffsFound = 0;

        $leftFilename = $input->getArgument('left');
        $rightFilename = $input->getArgument('right');

        $stream = new StreamHandler('php://output', Logger::INFO);
        $stream->setFormatter(new LineFormatter('%message%' . "\n"));

        $this->logger = new Logger('browscap');
        $this->logger->pushHandler($stream);
        $this->logger->pushHandler(new ErrorLogHandler(ErrorLogHandler::OPERATING_SYSTEM, Logger::NOTICE));

        ErrorHandler::register($this->logger);

        $iniParserLeft = new IniParser($leftFilename);
        $leftFile = $iniParserLeft->setShouldSort(true)->parse();

        if (!$rightFilename || !file_exists($rightFilename)) {
            $cache_dir = sys_get_temp_dir() . '/browscap-diff/' . microtime(true) . '/';

            if (!file_exists($cache_dir)) {
                mkdir($cache_dir, 0777, true);
            }

            $this->logger->log(Logger::INFO, 'right file not set or invalid - creating right file from resources');
            $resourceFolder = __DIR__ . BuildCommand::DEFAULT_RESOURCES_FOLDER;

            $collection = CollectionCreator::createDataCollection('temporary-version', $resourceFolder);

            $version = $collection->getVersion();
            $dateUtc = $collection->getGenerationDate()->format('l, F j, Y \a\t h:i A T');
            $date    = $collection->getGenerationDate()->format('r');

            $comments = array(
                'Provided courtesy of http://browscap.org/',
                'Created on ' . $dateUtc,
                'Keep up with the latest goings-on with the project:',
                'Follow us on Twitter <https://twitter.com/browscap>, or...',
                'Like us on Facebook <https://facebook.com/browscap>, or...',
                'Collaborate on GitHub <https://github.com/browscap>, or...',
                'Discuss on Google Groups <https://groups.google.com/forum/#!forum/browscap>.'
            );

            $collectionParser = new CollectionParser();
            $collectionParser->setDataCollection($collection);
            $collectionData = $collectionParser->parse();

            $iniGenerator = new BrowscapIniGenerator();
            $iniGenerator->setCollectionData($collectionData);

            $rightFilename = $cache_dir . 'full_php_browscap.ini';

            $iniGenerator
                ->setOptions(true, true, false)
                ->setComments($comments)
                ->setVersionData(array('version' => $version, 'released' => $date))
            ;

            file_put_contents($rightFilename, $iniGenerator->generate());
        }

        $iniParserRight = new IniParser($rightFilename);
        $rightFile = $iniParserRight->setShouldSort(true)->parse();

        $ltrDiff = $this->recursiveArrayDiff($leftFile, $rightFile);
        $rtlDiff = $this->recursiveArrayDiff($rightFile, $leftFile);

        if (count($ltrDiff) || count($rtlDiff)) {
            $this->logger->log(Logger::INFO, 'The following differences have been found:');
            $sectionsRead = array();

            foreach ($ltrDiff as $section => $props) {
                if (isset($rightFile[$section]) && is_array($rightFile[$section])) {
                    $this->compareSectionProperties($section, $props, (isset($rtlDiff[$section]) ? $rtlDiff[$section] : null), $rightFile[$section]);
                } else {
                    $this->logger->log(Logger::INFO, $section . "\n" . 'Whole section only on LEFT');
                    $this->diffsFound++;
                }

                $sectionsRead[] = $section;
            }

            foreach ($rtlDiff as $section => $props) {
                if (in_array($section, $sectionsRead)) {
                    continue;
                }

                if (isset($leftFile[$section]) && is_array($leftFile[$section])) {
                    $this->compareSectionProperties($section, (isset($ltrDiff[$section]) ? $ltrDiff[$section] : null), $props, $rightFile[$section]);
                } else {
                    $this->logger->log(Logger::INFO, $section . "\n" . 'Whole section only on RIGHT');
                    $this->diffsFound++;
                }
            }

            $msg = sprintf('%sThere %s %d difference%s found in the comparison.', "\n", ($this->diffsFound == 1 ? 'was'  : 'were'), $this->diffsFound, ($this->diffsFound == 1 ? '' : 's'));
            $this->logger->log(Logger::INFO, $msg);
        } else {
            $this->logger->log(Logger::INFO, 'No differences found, hooray!');
        }

        $this->logger->log(Logger::INFO, 'Diff done.');
    }

    /**
     * @param string $section
     * @param array  $leftPropsDifferences
     * @param array  $rightPropsDifferences
     * @param array  $rightProps
     */
    public function compareSectionProperties($section, $leftPropsDifferences, $rightPropsDifferences, $rightProps)
    {
        $this->logger->log(Logger::INFO, $section);

        // Diff the properties
        $propsRead = array();

        if (isset($leftPropsDifferences)) {
            foreach ($leftPropsDifferences as $prop => $value) {
                if (isset($rightProps[$prop])) {
                    $msg = sprintf('"%s" differs (L / R): %s / %s', $prop, $value, $rightProps[$prop]);
                    $this->logger->log(Logger::INFO, $msg);
                    $this->diffsFound++;
                } else {
                    $msg = sprintf('"%s" is only on the LEFT', $prop);
                    $this->logger->log(Logger::INFO, $msg);
                    $this->diffsFound++;
                }

                $propsRead[] = $prop;
            }
        }

        if (isset($rightPropsDifferences)) {
            foreach ($rightPropsDifferences as $prop => $value) {
                if (in_array($prop, $propsRead)) {
                    continue;
                }

                $msg = sprintf('"%s" is only on the RIGHT', $prop);
                $this->logger->log(Logger::INFO, $msg);
                $this->diffsFound++;
            }
        }
    }

    /**
     * @param array $leftArray
     * @param array $rightArray
     *
     * @return array
     */
    public function recursiveArrayDiff($leftArray, $rightArray)
    {
        $diffs = array();

        foreach ($leftArray as $key => $value) {
            if (array_key_exists($key, $rightArray)) {
                if (is_array($value)) {
                    $childDiffs = $this->recursiveArrayDiff($value, $rightArray[$key]);

                    if (count($childDiffs)) {
                        $diffs[$key] = $childDiffs;
                    }
                } else {
                    if ($value != $rightArray[$key]) {
                        $diffs[$key] = $value;
                    }
                }
            } else {
                $diffs[$key] = $value;
            }
        }

        return $diffs;
    }
}
