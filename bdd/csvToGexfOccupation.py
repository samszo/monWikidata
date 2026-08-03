import pandas as pd
import networkx as nx

# 1. Chargement du fichier
df = pd.read_csv('wikidata_occupations.csv')

nodes_set = set()
edges_set = set()

# 2. Parcours des lignes pour extraire les chemins hiérarchiques
for _, row in df.iterrows():
    # On retire les valeurs vides (NaN) et on convertit la ligne en liste
    cols = row.dropna().tolist()
    
    # Création des relations (source -> cible)
    for i in range(len(cols) - 1):
        edges_set.add((cols[i], cols[i+1]))
        
    # Si la ligne n'a pas de sous-classe, on capture le noeud isolé
    if 'subclass' in row and pd.isna(row['subclass']):
        nodes_set.add(row['id'])

# Compléter la liste des noeuds avec tous ceux présents dans les arêtes
for source, target in edges_set:
    nodes_set.add(source)
    nodes_set.add(target)

# 3. Construction du graphe avec NetworkX
G = nx.DiGraph() # Création d'un graphe orienté (Directed Graph)

# Ajout des nœuds
for node in nodes_set:
    G.add_node(node)

# Ajout des arêtes
for source, target in edges_set:
    G.add_edge(source, target)

# 4. Exportation au format GEXF
output_filename = 'occupations_graph.gexf'
nx.write_gexf(G, output_filename)

# Rapport d'exécution
print(f"Fichier exporté avec succès : {output_filename}")
print(f"Topologie : {G.number_of_nodes()} nœuds et {G.number_of_edges()} arêtes.")