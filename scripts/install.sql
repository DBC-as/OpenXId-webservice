/**
@DEPRECATED Use create_tables.php instead.
*/
DROP TABLE oxid_ids;
DROP TABLE oxid_id_types;

CREATE TABLE oxid_id_types (
  id   integer PRIMARY KEY,
  name varchar(10)
);

CREATE TABLE oxid_ids (
  id        serial PRIMARY KEY,
  idtype    integer REFERENCES oxid_id_types(id),
  idvalue   varchar(32),
  recordid  integer,
  clusterid integer
);

INSERT INTO oxid_id_types (id, name) VALUES (0, 'faust');
INSERT INTO oxid_id_types (id, name) VALUES (1, 'ean');
INSERT INTO oxid_id_types (id, name) VALUES (2, 'issn');
INSERT INTO oxid_id_types (id, name) VALUES (3, 'local');
INSERT INTO oxid_id_types (id, name) VALUES (4, 'oclc');

CREATE INDEX oxid_id_types_id_idx ON oxid_id_types (id);

CREATE INDEX oxid_ids_id_idx ON oxid_ids (id);
CREATE INDEX oxid_ids_recordid_idx ON oxid_ids (recordid);
CREATE INDEX oxid_ids_clusterid_idx ON oxid_ids (clusterid);
