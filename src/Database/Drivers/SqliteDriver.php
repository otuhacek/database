<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\Database\Drivers;

use Nette;


/**
 * Supplemental SQLite3 database driver.
 */
class SqliteDriver extends PdoDriver
{
	/** Datetime format */
	private string $fmtDateTime;


	public function connect(string $dsn, string $user = null, string $password = null, array $options = null): void
	{
		parent::connect($dsn, $user, $password, $options);
		$this->fmtDateTime = $options['formatDateTime'] ?? 'U';
	}


	public function detectExceptionClass(\PDOException $e): ?string
	{
		$code = $e->errorInfo[1] ?? null;
		$msg = $e->getMessage();
		if ($code !== 19) {
			return null;

		} elseif (
			str_contains($msg, 'must be unique')
			|| str_contains($msg, 'is not unique')
			|| str_contains($msg, 'UNIQUE constraint failed')
		) {
			return Nette\Database\UniqueConstraintViolationException::class;

		} elseif (
			str_contains($msg, 'may not be null')
			|| str_contains($msg, 'NOT NULL constraint failed')
		) {
			return Nette\Database\NotNullConstraintViolationException::class;

		} elseif (
			str_contains($msg, 'foreign key constraint failed')
			|| str_contains($msg, 'FOREIGN KEY constraint failed')
		) {
			return Nette\Database\ForeignKeyConstraintViolationException::class;

		} else {
			return Nette\Database\ConstraintViolationException::class;
		}
	}


	/********************* SQL ****************d*g**/


	public function delimite(string $name): string
	{
		return '[' . strtr($name, '[]', '  ') . ']';
	}


	public function formatDateTime(\DateTimeInterface $value): string
	{
		return $value->format($this->fmtDateTime);
	}


	public function formatDateInterval(\DateInterval $value): string
	{
		throw new Nette\NotSupportedException;
	}


	public function formatLike(string $value, int $pos): string
	{
		$value = addcslashes(substr($this->pdo->quote($value), 1, -1), '%_\\');
		return ($pos <= 0 ? "'%" : "'") . $value . ($pos >= 0 ? "%'" : "'") . " ESCAPE '\\'";
	}


	public function applyLimit(string &$sql, ?int $limit, ?int $offset): void
	{
		if ($limit < 0 || $offset < 0) {
			throw new Nette\InvalidArgumentException('Negative offset or limit.');

		} elseif ($limit !== null || $offset) {
			$sql .= ' LIMIT ' . ($limit ?? '-1')
				. ($offset ? ' OFFSET ' . $offset : '');
		}
	}


	/********************* reflection ****************d*g**/


	public function getTables(): array
	{
		$tables = [];
		foreach ($this->pdo->query("
			SELECT name, type = 'view' as view FROM sqlite_master WHERE type IN ('table', 'view') AND name NOT LIKE 'sqlite_%'
			UNION ALL
			SELECT name, type = 'view' as view FROM sqlite_temp_master WHERE type IN ('table', 'view') AND name NOT LIKE 'sqlite_%'
			ORDER BY name
		") as $row) {
			$tables[] = [
				'name' => $row['name'],
				'view' => (bool) $row['view'],
			];
		}

		return $tables;
	}


	public function getColumns(string $table): array
	{
		$meta = $this->pdo->query("
			SELECT sql FROM sqlite_master WHERE type = 'table' AND name = {$this->pdo->quote($table)}
			UNION ALL
			SELECT sql FROM sqlite_temp_master WHERE type = 'table' AND name = {$this->pdo->quote($table)}
		")->fetch();

		$columns = [];
		foreach ($this->pdo->query("PRAGMA table_info({$this->delimite($table)})") as $row) {
			$column = $row['name'];
			$pattern = "/(\"$column\"|`$column`|\\[$column\\]|$column)\\s+[^,]+\\s+PRIMARY\\s+KEY\\s+AUTOINCREMENT/Ui";
			$type = explode('(', $row['type']);
			$columns[] = [
				'name' => $column,
				'table' => $table,
				'nativetype' => strtoupper($type[0]),
				'size' => isset($type[1]) ? (int) $type[1] : null,
				'nullable' => !$row['notnull'],
				'default' => $row['dflt_value'],
				'autoincrement' => $meta && preg_match($pattern, (string) $meta['sql']),
				'primary' => $row['pk'] > 0,
				'vendor' => $row,
			];
		}
		return $columns;
	}


	public function getIndexes(string $table): array
	{
		$indexes = [];
		foreach ($this->pdo->query("PRAGMA index_list({$this->delimite($table)})") as $row) {
			$indexes[$row['name']]['name'] = $row['name'];
			$indexes[$row['name']]['unique'] = (bool) $row['unique'];
			$indexes[$row['name']]['primary'] = false;
		}

		foreach ($indexes as $index => $values) {
			$res = $this->pdo->query("PRAGMA index_info({$this->delimite($index)})");
			while ($row = $res->fetch()) {
				$indexes[$index]['columns'][$row['seqno']] = $row['name'];
			}
		}

		$columns = $this->getColumns($table);
		foreach ($indexes as $index => $values) {
			$column = $indexes[$index]['columns'][0];
			foreach ($columns as $info) {
				if ($column === $info['name']) {
					$indexes[$index]['primary'] = (bool) $info['primary'];
					break;
				}
			}
		}
		if (!$indexes) { // @see http://www.sqlite.org/lang_createtable.html#rowid
			foreach ($columns as $column) {
				if ($column['vendor']['pk']) {
					$indexes[] = [
						'name' => 'ROWID',
						'unique' => true,
						'primary' => true,
						'columns' => [$column['name']],
					];
					break;
				}
			}
		}

		return array_values($indexes);
	}


	public function getForeignKeys(string $table): array
	{
		$keys = [];
		foreach ($this->pdo->query("PRAGMA foreign_key_list({$this->delimite($table)})") as $row) {
			$keys[$row['id']]['name'] = $row['id']; // foreign key name
			$keys[$row['id']]['local'] = $row['from']; // local columns
			$keys[$row['id']]['table'] = $row['table']; // referenced table
			$keys[$row['id']]['foreign'] = $row['to']; // referenced columns

			if ($keys[$row['id']]['foreign'][0] == null) {
				$keys[$row['id']]['foreign'] = null;
			}
		}
		return array_values($keys);
	}


	public function getColumnTypes(\PDOStatement $statement): array
	{
		$types = [];
		$count = $statement->columnCount();
		for ($col = 0; $col < $count; $col++) {
			$meta = $statement->getColumnMeta($col);
			if (isset($meta['sqlite:decl_type'])) {
				$types[$meta['name']] = in_array($meta['sqlite:decl_type'], ['DATE', 'DATETIME'], true)
					? Nette\Database\IStructure::FIELD_UNIX_TIMESTAMP
					: Nette\Database\Helpers::detectType($meta['sqlite:decl_type']);
			} elseif (isset($meta['native_type'])) {
				$types[$meta['name']] = Nette\Database\Helpers::detectType($meta['native_type']);
			}
		}
		return $types;
	}


	public function isSupported(string $item): bool
	{
		return $item === self::SUPPORT_MULTI_INSERT_AS_SELECT || $item === self::SUPPORT_SUBSELECT || $item === self::SUPPORT_MULTI_COLUMN_AS_OR_COND;
	}
}
