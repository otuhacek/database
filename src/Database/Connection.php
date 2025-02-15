<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\Database;

use Nette;
use Nette\Utils\Arrays;


/**
 * Represents a connection between PHP and a database server.
 */
class Connection
{
	use Nette\SmartObject;

	/** @var array<callable(self): void>  Occurs after connection is established */
	public iterable $onConnect = [];

	/** @var array<callable(self, ResultSet|DriverException): void>  Occurs after query is executed */
	public iterable $onQuery = [];

	private array $params;
	private array $options;
	private ?Driver $driver = null;
	private SqlPreprocessor $preprocessor;

	/** @var callable(array, ResultSet): array */
	private $rowNormalizer = [Helpers::class, 'normalizeRow'];
	private ?string $sql = null;
	private int $transactionDepth = 0;


	public function __construct(string $dsn, string $user = null, string $password = null, array $options = null)
	{
		$this->params = [$dsn, $user, $password];
		$this->options = (array) $options;

		if (empty($options['lazy'])) {
			$this->connect();
		}
	}


	public function connect(): void
	{
		if ($this->driver) {
			return;
		}
		$dsn = explode(':', $this->params[0])[0];
		$class = empty($this->options['driverClass'])
			? 'Nette\Database\Drivers\\' . ucfirst(str_replace('sql', 'Sql', $dsn)) . 'Driver'
			: $this->options['driverClass'];
		if (!class_exists($class)) {
			throw new ConnectionException("Invalid data source '$dsn'.");
		}

		$this->driver = new $class;
		$this->driver->connect($this->params[0], $this->params[1], $this->params[2], $this->options);
		$this->preprocessor = new SqlPreprocessor($this);
		Arrays::invoke($this->onConnect, $this);
	}


	public function reconnect(): void
	{
		$this->disconnect();
		$this->connect();
	}


	public function disconnect(): void
	{
		$this->driver = null;
	}


	public function getDsn(): string
	{
		return $this->params[0];
	}


	/** deprecated use getDriver()->getPdo() */
	public function getPdo(): \PDO
	{
		$this->connect();
		return $this->driver->getPdo();
	}


	public function getDriver(): Driver
	{
		$this->connect();
		return $this->driver;
	}


	/** @deprecated use getDriver() */
	public function getSupplementalDriver(): Driver
	{
		trigger_error(__METHOD__ . '() is deprecated, use getDriver()', E_USER_DEPRECATED);
		$this->connect();
		return $this->driver;
	}


	public function setRowNormalizer(?callable $normalizer): self
	{
		$this->rowNormalizer = $normalizer;
		return $this;
	}


	public function getInsertId(string $sequence = null): string
	{
		$this->connect();
		return $this->driver->getInsertId($sequence);
	}


	public function quote(string $string): string
	{
		$this->connect();
		return $this->driver->quote($string);
	}


	public function beginTransaction(): void
	{
		if ($this->transactionDepth !== 0) {
			throw new \LogicException(__METHOD__ . '() call is forbidden inside a transaction() callback');
		}

		$this->query('::beginTransaction');
	}


	public function commit(): void
	{
		if ($this->transactionDepth !== 0) {
			throw new \LogicException(__METHOD__ . '() call is forbidden inside a transaction() callback');
		}

		$this->query('::commit');
	}


	public function rollBack(): void
	{
		if ($this->transactionDepth !== 0) {
			throw new \LogicException(__METHOD__ . '() call is forbidden inside a transaction() callback');
		}

		$this->query('::rollBack');
	}


	public function transaction(callable $callback): mixed
	{
		if ($this->transactionDepth === 0) {
			$this->beginTransaction();
		}

		$this->transactionDepth++;
		try {
			$res = $callback($this);
		} catch (\Throwable $e) {
			$this->transactionDepth--;
			if ($this->transactionDepth === 0) {
				$this->rollback();
			}
			throw $e;
		}

		$this->transactionDepth--;
		if ($this->transactionDepth === 0) {
			$this->commit();
		}

		return $res;
	}


	/**
	 * Generates and executes SQL query.
	 * @param  literal-string  $sql
	 */
	public function query(string $sql, ...$params): ResultSet
	{
		[$this->sql, $params] = $this->preprocess($sql, ...$params);
		try {
			$result = new ResultSet($this, $this->sql, $params, $this->rowNormalizer);
		} catch (DriverException $e) {
			Arrays::invoke($this->onQuery, $this, $e);
			throw $e;
		}
		Arrays::invoke($this->onQuery, $this, $result);
		return $result;
	}


	/** @deprecated  use query() */
	public function queryArgs(string $sql, array $params): ResultSet
	{
		trigger_error(__METHOD__ . '() is deprecated, use query()', E_USER_DEPRECATED);
		return $this->query($sql, ...$params);
	}


	/**
	 * @param  literal-string  $sql
	 * @return array{string, array}
	 */
	public function preprocess(string $sql, ...$params): array
	{
		$this->connect();
		return $params
			? $this->preprocessor->process(func_get_args())
			: [$sql, []];
	}


	public function getLastQueryString(): ?string
	{
		return $this->sql;
	}


	/********************* shortcuts ****************d*g**/


	/**
	 * Shortcut for query()->fetch()
	 * @param  literal-string  $sql
	 */
	public function fetch(string $sql, ...$params): ?Row
	{
		return $this->query($sql, ...$params)->fetch();
	}


	/**
	 * Shortcut for query()->fetchField()
	 * @param  literal-string  $sql
	 */
	public function fetchField(string $sql, ...$params): mixed
	{
		return $this->query($sql, ...$params)->fetchField();
	}


	/**
	 * Shortcut for query()->fetchFields()
	 * @param  literal-string  $sql
	 */
	public function fetchFields(string $sql, ...$params): ?array
	{
		return $this->query($sql, ...$params)->fetchFields();
	}


	/**
	 * Shortcut for query()->fetchPairs()
	 * @param  literal-string  $sql
	 */
	public function fetchPairs(string $sql, ...$params): array
	{
		return $this->query($sql, ...$params)->fetchPairs();
	}


	/**
	 * Shortcut for query()->fetchAll()
	 * @param  literal-string  $sql
	 */
	public function fetchAll(string $sql, ...$params): array
	{
		return $this->query($sql, ...$params)->fetchAll();
	}


	public static function literal(string $value, ...$params): SqlLiteral
	{
		return new SqlLiteral($value, $params);
	}
}
