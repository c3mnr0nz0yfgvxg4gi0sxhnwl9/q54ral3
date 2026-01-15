<?php
//
// Performance comparison, DELETE FROM
//
// * table not referenced by other tables,
// * table referenced by 1 column in another table
// * table referenced by 10 columns in another table
//
// Uses SQLite3.
//
// For the full explanation, see
// "How much do REFERENCES constraints affect DELETE FROM in a relational database? (performance test)"
// https://cybertiggyr.com/q54ral3.html
//

define("DATABASE_PATHNAME", "db1.sqlite3");
define("COUNT", 200000);

if (file_exists(DATABASE_PATHNAME)) {
  unlink(DATABASE_PATHNAME);
}
$db = new SQLite3(DATABASE_PATHNAME);
if (! $db) {
  throw new Exception("Can't open/create the database.");
}

////////////////////////////////////////////////////////////////////////
// DATABASE UTILITIES
//
// Functions to keep other code simpler.  Most of these are motivated by
// an obsession for error-checking.  Most of them perform some standard
// database action & toss on error.  Also, they use the DB global.
//

//
// The PHP runtime will close the database on exit if I don't, but as
// stated, obsession for error-checking (& cleanup).
//
function My_Shutdown() {
  global $db;

  if ($db) {
    $db->close(); $db = null;
  }
}
register_shutdown_function("My_Shutdown");

function My_Prepare($sql) {
  global $db;

  $stmt = $db->prepare($sql);
  if (! $stmt) {
    $fmt = "Can't prepare \"%s\".  %s";
    $msg = sprintf($fmt, $sql, $db->lastErrorMsg());
    throw new Exception($msg);
  }
  return $stmt;
}

//
// SQLite3Stmt::bindValue() usually returns a loosey-goosey true value on
// success, but sometimes it returns a loosey-goosey false.  In that 2nd
// case, we can check the database's error _code_ to see if we really have
// an error.
//
// I've never figured out why bindValue() sometimes returns a loosey-goosey
// false when there was no error.  Or maybe there was an error that I have
// not been able to detect.
//
function My_BindVal($stmt, $field, $value, $type) {
  global $db;

  if (! $stmt->bindValue($field, $value, $type) &&
      0 !== $db->lastErrorCode()) {
    $fmt = "Can't bind \"%s\" to field %s.  %s (%d)";
    $msg = sprintf($fmt, $value, $field, $db->lastErrorMsg(),
                   $db->lastErrorCode());
    throw new Exception($msg);
  }
}

function My_Execute($stmt) {
  global $db;

  $rows = $stmt->execute();
  if (! $rows) {
    $fmt = "Failed to execute.  %s";
    $msg = sprintf($fmt, $db->lastErrorMsg());
    throw new Exception($msg);
  }
  return $rows;
}

//
// Execute the prepared statement, then close it.  Toss on error.
// Returns nothing because, after the statement is closed, the
// results (what I often call ROWS) is closed, too.
//
function My_Execute_Close($stmt) {
  try {
    My_Execute($stmt);
  } finally {
    $stmt->close();
  }
}

function My_Query($sql) {
  global $db;

  $rows = $db->exec($sql);
  if (! $rows) {
    $fmt = "Can't execute \"%s\".  %s";
    $msg = sprintf($fmt, $sql, $db->lastErrorMsg());
  }
  return $rows;
}

function My_Exec($sql) {
  My_Query($sql);
  return null;
}

function My_Table_Count( $tablename ) {
  $sql = sprintf("SELECT COUNT(*) FROM %s;", $tablename);
  $stmt = My_Prepare($sql);
  $rows = My_Execute($stmt);
  $row = $rows->fetchArray();
  $count = $row[0];
  $stmt->close(); $stmt = null;
  return $count;
}

function Count_Dep0() {
  return My_Table_Count("Dep0");
}

function Dump_Dep0() {
  $stmt = My_Prepare("SELECT * FROM Dep0;");
  $rows = My_Execute($stmt);
  printf("dep_id     A     B     C\n");
  printf("------  ----  ----  ----\n");
  $row = $rows->fetchArray();
  while (is_array($row)) {
    printf("%4d  %4d  %4d  %4d\n", $row["dep_id"], $row["col_a"],
           $row["col_b"], $row["col_c"]);
    $row = $rows->fetchArray();
  }
  $stmt->close(); $stmt = null;
}

////////////////////////////////////////////////////////////////////////
// CREATE THE DATABASE
//

printf("Create the database.\n");
My_Exec("PRAGMA foreign_keys = ON;");

//
// Create all the tables with one command.
// We have 0 REFERENCES constraints, 1 REFERENCES constraints, &
// 10 REFERENCES constraints.
//
// Table Base0 has some rows.  Dep0 refers to it, though we do NOT
// use a REFERENCES statement.  So that's 0 REFERENCES constraints.
//
// Table Base1 has some rows.  Dep1 refers to it using 1 REFERENCES
// constraint.  That's our 1-constraint case.
//
// Table Base10 has some rows.  Dep10 refers to it using 10 REFERENCES
// constraints.  That's our 10-constraint case.
//
$sql = <<<END_SQL
  BEGIN TRANSACTION;

  CREATE TABLE Base0 (
    base_id INTEGER PRIMARY KEY,
    val_a INTEGER,
    val_b TEXT
  );

  CREATE TABLE Dep0 (
    dep_id INTEGER PRIMARY KEY,
    col_a INTEGER NOT NULL,
    col_b INTEGER NOT NULL,
    col_c INTEGER NOT NULL,
    col_d INTEGER NOT NULL,
    col_e INTEGER NOT NULL,
    col_f INTEGER NOT NULL,
    col_g INTEGER NOT NULL,
    col_h INTEGER NOT NULL,
    col_i INTEGER NOT NULL,
    col_j INTEGER NOT NULL
  );

  CREATE TABLE Base1 (
    base_id INTEGER PRIMARY KEY,
    val_a INTEGER,
    val_b TEXT
  );

  CREATE TABLE Dep1 (
    dep_id INTEGER PRIMARY KEY,
    col_a INTEGER NOT NULL REFERENCES Base1(base_id),
    col_b INTEGER NOT NULL,
    col_c INTEGER NOT NULL,
    col_d INTEGER NOT NULL,
    col_e INTEGER NOT NULL,
    col_f INTEGER NOT NULL,
    col_g INTEGER NOT NULL,
    col_h INTEGER NOT NULL,
    col_i INTEGER NOT NULL,
    col_j INTEGER NOT NULL
  );

  CREATE TABLE Base10 (
    base_id INTEGER PRIMARY KEY,
    val_a INTEGER,
    val_b TEXT
  );

  CREATE TABLE Dep10 (
    dep_id INTEGER PRIMARY KEY,
    col_a INTEGER NOT NULL REFERENCES Base10(base_id),
    col_b INTEGER NOT NULL REFERENCES Base10(base_id),
    col_c INTEGER NOT NULL REFERENCES Base10(base_id),
    col_d INTEGER NOT NULL REFERENCES Base10(base_id),
    col_e INTEGER NOT NULL REFERENCES Base10(base_id),
    col_f INTEGER NOT NULL REFERENCES Base10(base_id),
    col_g INTEGER NOT NULL REFERENCES Base10(base_id),
    col_h INTEGER NOT NULL REFERENCES Base10(base_id),
    col_i INTEGER NOT NULL REFERENCES Base10(base_id),
    col_j INTEGER NOT NULL REFERENCES Base10(base_id)
  );

  COMMIT TRANSACTION;
END_SQL;
My_Exec($sql);

////////////////////////////////////////////////////////////////////////
// POPULATE ALL THE TABLES
//
// For the base tables, we simply insert new rows.  To give them something
// to store, even if it's meaningless, we'll put random integers into
// their "val_a" columns.  We'll put those same integers as strings into
// their "val_b" columns.  All the Base tables will have the same rows
// (same random numbers) inserted in the same order.
//
// After we do all that, we'll populate the Dep tables.  Each Dep table
// will have the same values as the other Dep tables. inserted in the same
// orders.  The differences are only that they REFERENCE different Base
// tables, but because of how we populated the Base tables, those values
// can be the same, though to SQLite3, they are the ids from different
// tables.  We'll refer to every 10 rows, then skip a row (so we can
// delete it later without violating a constraint).
//

//
// Populate the Base tables
//
printf("Populate the Base tables.\n");
My_Exec("BEGIN TRANSACTION;");
$sql_0 = "INSERT INTO Base0 (val_a, val_b) VALUES (:base0_a, :base0_b);";
$sql_1 = "INSERT INTO Base1 (val_a, val_b) VALUES (:base1_a, :base1_b);";
$sql_10 = "INSERT INTO Base10 (val_a, val_b) VALUES (:base10_a, :base10_b);";

$stmt_0 = My_Prepare($sql_0);
$stmt_1 = My_Prepare($sql_1);
$stmt_10 = My_Prepare($sql_10);
for ($i = 1; $i <= COUNT; ++$i) {
  $a = 1000 + $i;                       // just some value
  $b = sprintf("%d", $a);               // just another value
  My_BindVal($stmt_0, ":base0_a", $a, SQLITE3_INTEGER);
  My_BindVal($stmt_0, ":base0_b", $b, SQLITE3_TEXT);
  My_Execute($stmt_0);

  My_BindVal($stmt_1, ":base1_a", $a, SQLITE3_INTEGER);
  My_BindVal($stmt_1, ":base1_b", $b, SQLITE3_TEXT);
  My_Execute($stmt_1);

  My_BindVal($stmt_10, ":base10_a", $a, SQLITE3_INTEGER);
  My_BindVal($stmt_10, ":base10_b", $b, SQLITE3_TEXT);
  My_Execute($stmt_10);
}
$stmt_0->close(); $stmt_0 = null; // obsessive/compulsive reference destruction
$stmt_1->close(); $stmt_1 = null;
$stmt_10->close(); $stmt_10 = null;
My_Exec("COMMIT TRANSACTION;");

//
// Populate the Dep tables
//
printf("Populate the Dep tables.\n");
$sql_0 = <<<END_SQL
  INSERT INTO Dep0 (col_a, col_b, col_c, col_d, col_e,
                    col_f, col_g, col_h, col_i, col_j)
  VALUES (:dep0_a, :dep0_b, :dep0_c, :dep0_d, :dep0_e,
          :dep0_f, :dep0_g, :dep0_h, :dep0_i, :dep0_j);
END_SQL;

$sql_1 = <<<END_SQL
  INSERT INTO Dep1 (col_a, col_b, col_c, col_d, col_e,
                    col_f, col_g, col_h, col_i, col_j)
  VALUES (:dep1_a, :dep1_b, :dep1_c, :dep1_d, :dep1_e,
          :dep1_f, :dep1_g, :dep1_h, :dep1_i, :dep1_j);
END_SQL;

$sql_10 = <<<END_SQL
  INSERT INTO Dep10 (col_a, col_b, col_c, col_d, col_e,
                    col_f, col_g, col_h, col_i, col_j)
  VALUES (:dep10_a, :dep10_b, :dep10_c, :dep10_d, :dep10_e,
          :dep10_f, :dep10_g, :dep10_h, :dep10_i, :dep10_j);
END_SQL;

$stmt_0 = My_Prepare($sql_0);
$stmt_1 = My_Prepare($sql_1);
$stmt_10 = My_Prepare($sql_10);
for ($i = 1; $i + 10 <= COUNT; $i = $i + 11) {
  $count_before = Count_Dep0();
  My_Exec("BEGIN TRANSACTION;");
  My_BindVal($stmt_0, ":dep0_a",  $i,      SQLITE3_INTEGER);
  My_BindVal($stmt_0, ":dep0_b",  $i + 1,  SQLITE3_INTEGER);
  My_BindVal($stmt_0, ":dep0_c",  $i + 2,  SQLITE3_INTEGER);
  My_BindVal($stmt_0, ":dep0_d",  $i + 3,  SQLITE3_INTEGER);
  My_BindVal($stmt_0, ":dep0_e",  $i + 4,  SQLITE3_INTEGER);
  My_BindVal($stmt_0, ":dep0_f",  $i + 5,  SQLITE3_INTEGER);
  My_BindVal($stmt_0, ":dep0_g",  $i + 6,  SQLITE3_INTEGER);
  My_BindVal($stmt_0, ":dep0_h",  $i + 7,  SQLITE3_INTEGER);
  My_BindVal($stmt_0, ":dep0_i",  $i + 8,  SQLITE3_INTEGER);
  My_BindVal($stmt_0, ":dep0_j",  $i + 9,  SQLITE3_INTEGER);
  My_Execute($stmt_0);
  My_Exec("COMMIT TRANSACTION;");
  $count_after = Count_Dep0();
  if ($count_before !== $count_after - 1) {
    $msg = sprintf("Insert %d into Dep0 didn't increae the count.", $i);
    throw new Exception($msg);
  }

  My_Exec("BEGIN TRANSACTION;");
  My_BindVal($stmt_1, ":dep1_a",  $i,      SQLITE3_INTEGER);
  My_BindVal($stmt_1, ":dep1_b",  $i + 1,  SQLITE3_INTEGER);
  My_BindVal($stmt_1, ":dep1_c",  $i + 2,  SQLITE3_INTEGER);
  My_BindVal($stmt_1, ":dep1_d",  $i + 3,  SQLITE3_INTEGER);
  My_BindVal($stmt_1, ":dep1_e",  $i + 4,  SQLITE3_INTEGER);
  My_BindVal($stmt_1, ":dep1_f",  $i + 5,  SQLITE3_INTEGER);
  My_BindVal($stmt_1, ":dep1_g",  $i + 6,  SQLITE3_INTEGER);
  My_BindVal($stmt_1, ":dep1_h",  $i + 7,  SQLITE3_INTEGER);
  My_BindVal($stmt_1, ":dep1_i",  $i + 8,  SQLITE3_INTEGER);
  My_BindVal($stmt_1, ":dep1_j",  $i + 9,  SQLITE3_INTEGER);
  My_Execute($stmt_1);
  My_Exec("COMMIT TRANSACTION;");

  My_Exec("BEGIN TRANSACTION;");
  My_BindVal($stmt_10, ":dep10_a", $i,      SQLITE3_INTEGER);
  My_BindVal($stmt_10, ":dep10_b", $i + 1,  SQLITE3_INTEGER);
  My_BindVal($stmt_10, ":dep10_c", $i + 2,  SQLITE3_INTEGER);
  My_BindVal($stmt_10, ":dep10_d", $i + 3,  SQLITE3_INTEGER);
  My_BindVal($stmt_10, ":dep10_e", $i + 4,  SQLITE3_INTEGER);
  My_BindVal($stmt_10, ":dep10_f", $i + 5,  SQLITE3_INTEGER);
  My_BindVal($stmt_10, ":dep10_g", $i + 6,  SQLITE3_INTEGER);
  My_BindVal($stmt_10, ":dep10_h", $i + 7,  SQLITE3_INTEGER);
  My_BindVal($stmt_10, ":dep10_i", $i + 8,  SQLITE3_INTEGER);
  My_BindVal($stmt_10, ":dep10_j", $i + 9,  SQLITE3_INTEGER);
  My_Execute($stmt_10);
  My_Exec("COMMIT TRANSACTION;");
}
$stmt_0->close(); $stmt_0 = null;
$stmt_1->close(); $stmt_1 = null;
$stmt_10->close(); $stmt_10 = null;

////////////////////////////////////////////////////////////////////////
// print some error checks

printf("There are %d / %d rows in Base0 / Dep0.\n", My_Table_Count("Base0"), My_Table_Count("Dep0"));
printf("There are %d / %d rows in Base1 / Dep1.\n", My_Table_Count("Base1"), My_Table_Count("Dep1"));
printf("There are %d / %d rows in Base10 / Dep10.\n", My_Table_Count("Base10"), My_Table_Count("Dep10"));

////////////////////////////////////////////////////////////////////////
// THE PERFORMANCE TEST
//
// The rest was preparation.  This is why we are here.
//
// We'll delete from each Base table separately so we can time
// their differences.  Because their corresponding Dep tables refer
// to 10 rows, then skip the 11th, we can delete every 11th row
// without violating constraints.
//
// We did not put any REFERENCES constrains in the Dep0 table, so
// deleting from the Base0 table should cause SQLite3 to spend no
// time checking REFERENCES constraints.  That deletion should be
// as quick as it can be.  (SQLite3 would allow us to delete all
// rows from the Base table, but we won't do that; we'll do the
// same thing for each of the Base tables.)
//
// We used one REFERENCES constraint in the Dep1 table, so
// deleting from the Base1 table should require checking a single
// REFERENCES constraint for each row.  We'll ensure that the rows
// we delete are not the targets of any REFERENCES in the Dep1
// table, so the delete should complete without error.
//
// We used a whopping 10 REFERENCES constraints in the Dep10 table, so
// deleting from the Base10 table will require 10 checks for each
// row.  We'll delete rows that are not the targets of any of those
// REFERENCES, so the delete should complete without error.  It
// could be very slow.
//

////////////////////////////////////////////////////////////////////////
//

// Execute the "DELETE FROM" & return the # of seconds consumed
function Do_Delete( $tablename ) {
  printf("%8s", $tablename);
  My_Exec("BEGIN TRANSACTION;");
  // Normally, using string manipulation to construct SQL leaves
  // you open to SQL Injection, but this program has no inputs,
  // uses only hard-coded values for the TABLENAMEs.  So it's okay
  // in practice, if not in theory.
  $sql = sprintf("DELETE FROM %s WHERE mod(base_id, 11) = 0;", $tablename);
  $start_time = time();
  My_Exec($sql);
  My_Exec("COMMIT TRANSACTION;");
  $end_time = time();
  printf("  %s seconds\n", $end_time - $start_time);
}

printf("Do the deletions.\n");
Do_Delete("Base0");
Do_Delete("Base1");
Do_Delete("Base10");
?>