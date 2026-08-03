import pandas as pd
import networkx as nx
import json

# 1. Chargement du fichier
fichier_csv = 'wikidata_occupations.csv'
df = pd.read_csv(fichier_csv)

# Dictionnaire pour stocker les nœuds (id -> label) afin d'éviter les doublons
# Set pour stocker les arêtes uniques
nodes = {} 
edges = set()

# 2. Extraction des données
for _, row in df.iterrows():
    # Capture du nœud de départ (parent)
    if pd.isna(row['id']):
        continue
        
    source_id = str(row['id'])
    source_label = str(row['label']) if pd.notna(row['label']) else source_id
    
    nodes[source_id] = source_label
    
    # Parcours des 11 enfants possibles (de 0 à 10)
    for i in range(11):
        col_cls = f'cls{i}'
        col_lbl = f'lbl{i}'
        
        # Si un enfant existe dans cette colonne
        if col_cls in row and pd.notna(row[col_cls]):
            target_id = str(row[col_cls])
            target_label = str(row[col_lbl]) if pd.notna(row[col_lbl]) else target_id
            
            # Ajout de l'enfant dans le dictionnaire des nœuds
            nodes[target_id] = target_label
            
            # Ajout de la relation (Nœud de départ -> Enfant)
            edges.add((source_id, target_id))

# ==========================================
# 3. Exportation au format JSON
# ==========================================
# Formatage adapté pour les bibliothèques web (ex: D3.js, Sigma.js)
nodes_list = [{"id": n_id, "label": n_lbl} for n_id, n_lbl in nodes.items()]
links_list = [{"source": s, "target": t} for s, t in edges]

graph_data = {
    "nodes": nodes_list,
    "links": links_list
}

fichier_json = 'occupations_graph.json'
# ensure_ascii=False permet de conserver les accents et caractères spéciaux de Wikidata
with open(fichier_json, 'w', encoding='utf-8') as f:
    json.dump(graph_data, f, ensure_ascii=False, separators=(',', ':'))

# ==========================================
# 4. Exportation au format GEXF (NetworkX)
# ==========================================
G = nx.DiGraph()

# Ajout des nœuds avec l'attribut "label" pour Gephi
for n_id, n_lbl in nodes.items():
    G.add_node(n_id, label=n_lbl)

# Ajout des arêtes
for s, t in edges:
    G.add_edge(s, t)

fichier_gexf = 'occupations_graph.gexf'
nx.write_gexf(G, fichier_gexf)

# Rapport d'exécution
print("--- Analyse de la structure sémantique terminée ---")
print(f"Topologie extraite : {len(nodes)} nœuds et {len(edges)} arêtes.")
print(f"Fichier JSON généré : {fichier_json}")
print(f"Fichier GEXF généré : {fichier_gexf}")