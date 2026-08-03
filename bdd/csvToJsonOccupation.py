import pandas as pd
import json

# 1. Chargement du fichier
df = pd.read_csv('wikidata_occupations.csv')

nodes_set = set()
edges_set = set()

# 2. Parcours des lignes pour extraire les chemins
for _, row in df.iterrows():
    # On retire les valeurs vides (NaN) et on convertit la ligne en liste
    cols = row.dropna().tolist()
    
    # Création des relations (source -> cible) en parcourant les colonnes adjacentes
    for i in range(len(cols) - 1):
        edges_set.add((cols[i], cols[i+1]))
        
    # Si la ligne n'a pas de sous-classe, on s'assure d'ajouter le noeud isolé
    if pd.isna(row['subclass']):
        nodes_set.add(row['id'])

# Ajout de tous les noeuds impliqués dans les relations
for source, target in edges_set:
    nodes_set.add(source)
    nodes_set.add(target)

# 3. Formatage pour le JSON
nodes_list = [{"id": n} for n in sorted(list(nodes_set))]
links_list = [{"source": s, "target": t} for s, t in sorted(list(edges_set))]

graph_data = {
    "nodes": nodes_list,
    "links": links_list
}

# 4. Sauvegarde du fichier
with open('occupations_graph.json', 'w', encoding='utf-8') as f:
    json.dump(graph_data, f, separators=(',', ':'))

print("Fichier JSON généré avec succès !")