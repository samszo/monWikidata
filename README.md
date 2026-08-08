# monWikidata — Explorateur de dump Wikidata

Application PHP pour explorer le dump JSON compressé de Wikidata (`wikidata-20240101-all.json.gz`) via une interface web avec stockage MariaDB. Chaque scan du dump (~100 Go) est interruptible et repris automatiquement après timeout.

---

## Prérequis

- PHP 8.3+ (`/opt/homebrew/bin/php`)
- MariaDB / MySQL
- Composer
- Dump Wikidata : `wikidata-20240101-all.json.gz` à la racine

## Installation

```bash
composer install
cp config.example.php config.php   # éditer host, port, dbname, user, pass
```

---

## Architecture

```mermaid
graph LR
    B([Navigateur])
    P[exploreDump.php<br/>PHP 8.3]
    DB[(MariaDB<br/>wikidata)]
    R[GzDumpReader<br/>seekToPosition]
    D[("Dump .json.gz<br/>~100 Go")]

    B -->|HTTP GET/POST| P
    P -->|HTML / CSV / JSON / GEXF| B
    P <-->|INSERT / SELECT| DB
    DB -.->|saved_pos| P
    P -->|seekToPosition(pos)| R
    R -->|nextJsonLine()| D
```

> La flèche pointillée `saved_pos` représente le mécanisme de reprise : la position byte dans le dump est sauvegardée dans `wikidata_scan_state` au moment du timeout et relue à la requête suivante.

---

## Base de données

```mermaid
erDiagram
    wikidata_search {
        int id PK
        varchar query
        varchar lang
        varchar p31
        varchar p279
        varchar entity_type
        bigint dump_position
        datetime created_at
    }
    wikidata_entities {
        varchar id PK
        varchar label
        text p31
        text p279
        int sitelinks
        int statements
        int externalIds
        bigint dump_line
        bigint dump_pos
        datetime exported_at
    }
    wikidata_search_entities {
        int search_id FK
        varchar entity_id FK
        varchar matched_in
    }
    wikidata_properties {
        int id PK
        varchar entity_id FK
        varchar property
        varchar value_type
        varchar value_id
        text value_str
        varchar rank
    }
    wikidata_geos {
        varchar id PK
        varchar label
        double latitude
        double longitude
        varchar source_prop
    }
    wikidata_occupations {
        varchar id PK
        varchar label
        text subclass_of
        varchar source_prop
    }
    wikidata_p279_graph {
        varchar src_id PK
        varchar dst_id PK
        datetime exported_at
    }
    wikidata_p279_class {
        varchar id PK
        varchar label
        bigint dump_line
        bigint dump_pos
        datetime updated_at
    }
    wikidata_nodes {
        varchar id PK
        varchar label
        text p31
        bigint dump_line
        bigint dump_pos
        datetime updated_at
    }
    wikidata_scan_state {
        varchar scan_key PK
        bigint dump_position
        int total_target
        int found_so_far
        text frontier_json
        datetime updated_at
    }

    wikidata_search ||--o{ wikidata_search_entities : "contient"
    wikidata_entities ||--o{ wikidata_search_entities : "référencée"
    wikidata_entities ||--o{ wikidata_properties : "possède"
    wikidata_p279_graph }o--|| wikidata_nodes : "src →"
    wikidata_p279_graph }o--|| wikidata_nodes : "→ dst"
```

---

## Onglets

### Recherche

Recherche full-text + filtres (P31, P279, type d'entité, identifiant exact, plage de lignes) dans le dump. Résultats exportables en CSV ou envoyés directement en base.

**Reprise automatique après timeout :**

```mermaid
sequenceDiagram
    participant U as Navigateur
    participant P as PHP
    participant DB as MariaDB
    participant D as Dump .gz

    U->>P: GET action=search / export_all
    P->>DB: SELECT dump_position FROM wikidata_search
    DB-->>P: saved_pos (0 si premier appel)
    P->>D: seekToPosition(saved_pos)
    loop nextJsonLine
        D-->>P: entité JSON
        P->>P: match + accumulation batch
        alt deadline atteinte
            P->>DB: UPDATE dump_position = pos_courante
            P-->>U: ⏱ timeout — lien Reprendre
        end
    end
    P->>DB: INSERT batch entités
    P-->>U: ✓ résultats / export terminé
```

### Entités (`wikidata_entities`)

Entités exportées depuis les recherches : label, description, P31, P279, Wikipedia, sitelinks, statements, identifiants externes, position dans le dump.

### Propriétés (`wikidata_properties`)

Scan du dump pour extraire les valeurs d'une propriété donnée (ex. P569 date de naissance) pour les entités déjà en base.

### Géos (`wikidata_geos`)

Extraction des coordonnées P625 pour les entités référencées par P19 (lieu de naissance). Résumable après timeout.

### Occupations (`wikidata_occupations`)

Extraction des entités référencées par P106 (occupation) avec leur hiérarchie `subclass_of` (P279 pipe-séparés).

---

## Réseau P279 — workflow en 3 passes

```mermaid
flowchart TD
    subgraph P1["Passe 1 — Initialiser (scan dump)"]
        P1A["Entité avec P279 ?"] --> P1B["wikidata_p279_graph\nsrc_id, dst_id"]
        P1A --> P1C["wikidata_nodes\nsrc → complet\ndst → stub id"]
        P1A --> P1D["wikidata_p279_class\nstubs P31 du src"]
        P1D --> P1E["Sync class ← nodes\nWHERE label IS NULL"]
    end
    subgraph P2["Passe 2 — Compléter (scan dump)"]
        P2A["id = dst_id ?"] --> P2B["wikidata_nodes\nmàj label, P31, pos"]
        P2A --> P2C["wikidata_p279_class\nstubs P31 des dst"]
        P2D["id dans p279_class ?"] --> P2E["wikidata_p279_class\nmàj label si NULL"]
    end
    subgraph P3["Passe 3 — Finaliser (scan dump)"]
        P3A["id dans p279_class\nlabel IS NULL ?"] --> P3B["wikidata_p279_class\nmàj label"]
    end
    subgraph BFS["BFS en base — instantané"]
        B1["Entité de départ"] --> B2["SQL : parcours\nwikidata_p279_graph"]
        B2 --> B3["Sous-graphe\nnœuds + liens"]
        B3 --> B4["Export JSON\nExport GEXF (Gephi)"]
    end

    P1 --> P2 --> P3 --> BFS
```

**Arborescence des classes (SQL récursif) :**

```sql
WITH RECURSIVE class_tree AS (
    SELECT c.id, c.label, 0 AS depth,
           CAST(CONCAT(c.id,' (',COALESCE(c.label,'?'),')') AS CHAR(10000)) AS path
    FROM wikidata_nodes n
    JOIN wikidata_p279_class c
      ON FIND_IN_SET(c.id, REPLACE(n.p31,'|',',')) > 0
    WHERE n.id = 'Q28640'
    UNION ALL
    SELECT g.dst_id, cls.label, ct.depth + 1,
           CONCAT(g.dst_id,' (',COALESCE(cls.label,'?'),') > ',ct.path)
    FROM class_tree ct
    JOIN wikidata_p279_graph g ON g.src_id = ct.id
    LEFT JOIN wikidata_p279_class cls ON cls.id = g.dst_id
    WHERE ct.depth < 20
)
SELECT id, label, depth, path FROM class_tree ORDER BY path;
```

---

## Exports

| Format | Onglet        | Contenu                                      |
|--------|---------------|----------------------------------------------|
| CSV    | Entités       | id, label, description, P31, P279, Wikipedia |
| CSV    | Propriétés    | property, value_type, value_id, value_str    |
| CSV    | Géos          | id, label, latitude, longitude               |
| CSV    | Occupations   | id, label, subclass_of                       |
| JSON   | Réseau P279   | `{ nodes: [{id,label,p31}], links: [{idSrc,idDst}] }` |
| GEXF   | Réseau P279   | Format Gephi — attribut `p31` sur les nœuds  |

---

## Constantes

| Constante    | Valeur        | Description                              |
|--------------|---------------|------------------------------------------|
| `TIME_LIMIT` | 10 800 s (3h) | Limite PHP par requête HTTP              |
| `MAX_RESULTS`| 200           | Résultats max par recherche              |
| `DUMP_FILE`  | `wikidata-20240101-all.json.gz` | Chemin du dump       |
| `PREF_LANG`  | `['fr','en']` | Langues préférées pour les labels        |

---

## Gestion du timeout

Toutes les fonctions de scan partagent le même pattern :

```php
$deadline = (int)$_SERVER['REQUEST_TIME'] + TIME_LIMIT - 5;
// ...
if (time() >= $deadline) { $timedOut = true; break; }
// ...
if ($timedOut) {
    saveScanState($key, $reader->getPosition(), $total, $found);
} else {
    clearScanState($key);
}
```

Le `deadline` est calculé depuis `REQUEST_TIME` (heure de début de la requête HTTP) pour rester aligné avec `set_time_limit()`. Les 5 secondes de marge laissent le temps au flush final en base.

---

## Licence

Données Wikidata sous licence [CC0](https://creativecommons.org/publicdomain/zero/1.0/). Code sous [MIT](LICENSE).
