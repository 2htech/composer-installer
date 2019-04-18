<?php

namespace App2h\Composer;

use Composer\Package\PackageInterface;
use Composer\Installer\LibraryInstaller;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Composer;
use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Util\Filesystem;

class ComposerInstaller extends LibraryInstaller {

	/** @var array */
	private static $paths = [
		'wwwDir' => 'www',
		'moduleDir' => 'app/modules',
		'resourceDir' => 'www/module-assets',
	];

	/** @var array */
	private $types = array(
		"app2h-module" => "app/modules"
	);

	/**
	 * {@inheritDoc}
	 */
	public function getInstallPath(PackageInterface $package) {
		return $this->types[$package->getType()]. '/' . $package->getPrettyName();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize() {
		$this->filesystem->ensureDirectoryExists(self::$paths['moduleDir']);
		$this->filesystem->ensureDirectoryExists(self::$paths['resourceDir']);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function finish() {
	}

	/**
	 * {@inheritDoc}
	 */
	public function install(InstalledRepositoryInterface $repo, PackageInterface $package) {
		parent::install($repo, $package);

		$this->initialize();
		$this->uninstallAssets($package);
		$this->installAssets($package);
		$this->finish();
	}

	/**
	 * {@inheritDoc}
	 */
	public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target) {
		$this->initialize();
		if ($this->isInstalled($repo, $initial)) {
			$this->uninstallAssets($initial);
		}

		parent::update($repo, $initial, $target);

		$this->installAssets($target);
		$this->finish();
	}

	/**
	 * {@inheritDoc}
	 */
	public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package) {
		if ($this->isInstalled($repo, $package)) {
			$this->initialize();
			$this->uninstallAssets($package);
			$this->finish();
		}

		parent::uninstall($repo, $package);
	}

	/**
	 * extra: {
	 * 		"assets": ["file.css", "file.js", "file.png"]
	 * }
	 * {@inheritDoc}
	 */
	protected function uninstallAssets(PackageInterface $package) {
		$extra = $package->getExtra();
		if (!isset($extra['assets'])) {
			return;
		}

		$this->removeAssets($package, $extra['assets']);
	}

	/**
	 * extra: {
	 * 		"assets": ["file.css", "file.js", "file.png"]
	 * }
	 * {@inheritDoc}
	 */
	protected function removeAssets(PackageInterface $package, array $extra) {
		$target = self::$paths['resourceDir'] . '/' . $package->getName();
		if (file_exists($target) && is_dir($target)) {
			$this->filesystem->removeDirectoryPhp($target);
		}
	}

	/**
	 * extra: {
	 * 		"assets": ["file.css", "file.js", "file.png"]
	 * }
	 * {@inheritDoc}
	 */
	protected function installAssets(PackageInterface $package) {
		$extra = $package->getExtra();
		if (!isset($extra['assets'])) {
			return;
		}

		foreach ((array) $extra['assets'] as $row) {
			$rowPath = $this->getInstallPath($package) . '/' . $row;
			if (strpos($rowPath, '*') !== FALSE) {
				foreach (glob($rowPath) as $file) {
					if (is_dir($file)) {
						$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($file), \RecursiveIteratorIterator::SELF_FIRST);
						$iterator->setMaxDepth(-1);

						foreach ($iterator as $item) {
							if (is_file($item)) {
								$this->moveToTarget($package, $item);
							}
						}

						continue;
					}
					$this->moveToTarget($package, $file);
				}
			} else {
				$this->moveToTarget($package, $row);
			}
		}
	}

	private function moveToTarget(PackageInterface $package, $absolutePath) {
		if (!file_exists($absolutePath)) {
			$this->io->write("<warning>Skipped installation of '$absolutePath' for package " . $package->getName() . " file not found in package.</warning>");
			return;
		}
		$target = self::$paths['resourceDir'] . '/' . $package->getName() . '/' . ltrim(str_replace($this->getInstallPath($package), '', $absolutePath), '\\/');
		if (!file_exists($target)) {
			$this->filesystem->ensureDirectoryExists(dirname($target));
			copy($absolutePath, $target);
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports($packageType) {
		return isset($this->types[$packageType]);
	}

}
