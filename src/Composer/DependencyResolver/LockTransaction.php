<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\DependencyResolver;

use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\Package\AliasPackage;
use Composer\Package\RootAliasPackage;
use Composer\Package\RootPackageInterface;
use Composer\Repository\ArrayRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Test\Repository\ArrayRepositoryTest;

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
class LockTransaction extends Transaction
{
    /**
     * packages in current lock file, platform repo or otherwise present
     * @var array
     */
    protected $presentMap;

    /**
     * Packages which cannot be mapped, platform repo, root package, other fixed repos
     * @var array
     */
    protected $unlockableMap;

    /**
     * @var array
     */
    protected $resultPackages;

    public function __construct(Pool $pool, $presentMap, $unlockableMap, $decisions)
    {
        $this->presentMap = $presentMap;
        $this->unlockableMap = $unlockableMap;

        $this->setResultPackages($pool, $decisions);
        parent::__construct($this->presentMap, $this->resultPackages['all']);

    }

    // TODO make this a bit prettier instead of the two text indexes?
    public function setResultPackages(Pool $pool, Decisions $decisions)
    {
        $this->resultPackages = array('all' => array(), 'non-dev' => array(), 'dev' => array());
        foreach ($decisions as $i => $decision) {
            $literal = $decision[Decisions::DECISION_LITERAL];

            if ($literal > 0) {
                $package = $pool->literalToPackage($literal);
                $this->resultPackages['all'][] = $package;
                if (!isset($this->unlockableMap[$package->id])) {
                    $this->resultPackages['non-dev'][] = $package;
                }
            }
        }
    }

    public function setNonDevPackages(LockTransaction $extractionResult)
    {
        $packages = $extractionResult->getNewLockPackages(false);

        $this->resultPackages['dev'] = $this->resultPackages['non-dev'];
        $this->resultPackages['non-dev'] = array();

        foreach ($packages as $package) {
            foreach ($this->resultPackages['dev'] as $i => $resultPackage) {
                // TODO this comparison is probably insufficient, aliases, what about modified versions? I guess they aren't possible?
                if ($package->getName() == $resultPackage->getName()) {
                    $this->resultPackages['non-dev'][] = $resultPackage;
                    unset($this->resultPackages['dev'][$i]);
                }
            }
        }
    }

    // TODO additionalFixedRepository needs to be looked at here as well?
    public function getNewLockPackages($devMode, $updateMirrors = false)
    {
        $packages = array();
        foreach ($this->resultPackages[$devMode ? 'dev' : 'non-dev'] as $package) {
            if (!($package instanceof AliasPackage) && !($package instanceof RootAliasPackage)) {
                // if we're just updating mirrors we need to reset references to the same as currently "present" packages' references to keep the lock file as-is
                // we do not reset references if the currently present package didn't have any, or if the type of VCS has changed
                if ($updateMirrors && !isset($this->presentMap[spl_object_hash($package)])) {
                    foreach ($this->presentMap as $presentPackage) {
                        if ($package->getName() == $presentPackage->getName() &&
                            $package->getVersion() == $presentPackage->getVersion() &&
                            $presentPackage->getSourceReference() &&
                            $presentPackage->getSourceType() === $package->getSourceType()
                        ) {
                            $package->setSourceDistReferences($presentPackage->getSourceReference());
                        }
                    }
                }
                $packages[] = $package;
            }
        }

        return $packages;
    }

    /**
     * Checks which of the given aliases from composer.json are actually in use for the lock file
     */
    public function getAliases($aliases)
    {
        $usedAliases = array();

        foreach ($this->resultPackages['all'] as $package) {
            if ($package instanceof AliasPackage) {
                if (isset($aliases[$package->getName()])) {
                    $usedAliases[$package->getName()] = $aliases[$package->getName()];
                }
            }
        }

        return $usedAliases;
    }
}
