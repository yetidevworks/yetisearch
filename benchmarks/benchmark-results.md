# YetiSearch Benchmark Results

These results showcase the performance of YetiSearch across various scenarios, demonstrating its efficiency and speed in handling search operations. The benchmarks were conducted using a dataset of 32,000 documents, each with an average size of 1KB. Running on a PHP 8.3.22 environment with SQLite 3.50.2, the tests were designed to evaluate both indexing and search performance.

```text
YetiSearch Benchmark Test
========================
Using trigram fuzzy search algorithm

Loading movies.json... Done! (31944 movies loaded in 0.0462 seconds)

Initializing YetiSearch... Done!
Clearing existing index... Done!
Indexing movies...
  Indexed: 1000 movies | Rate: 4,087 movies/sec | Elapsed: 0.24s
  Indexed: 2000 movies | Rate: 4,389 movies/sec | Elapsed: 0.46s
  Indexed: 3000 movies | Rate: 4,521 movies/sec | Elapsed: 0.66s
  Indexed: 4000 movies | Rate: 4,598 movies/sec | Elapsed: 0.87s
  Indexed: 5000 movies | Rate: 4,596 movies/sec | Elapsed: 1.09s
  Indexed: 6000 movies | Rate: 4,634 movies/sec | Elapsed: 1.29s
  Indexed: 7000 movies | Rate: 4,659 movies/sec | Elapsed: 1.50s
  Indexed: 8000 movies | Rate: 4,649 movies/sec | Elapsed: 1.72s
  Indexed: 9000 movies | Rate: 4,657 movies/sec | Elapsed: 1.93s
  Indexed: 10000 movies | Rate: 4,675 movies/sec | Elapsed: 2.14s
  Indexed: 11000 movies | Rate: 4,680 movies/sec | Elapsed: 2.35s
  Indexed: 12000 movies | Rate: 4,638 movies/sec | Elapsed: 2.59s
  Indexed: 13000 movies | Rate: 4,591 movies/sec | Elapsed: 2.83s
  Indexed: 14000 movies | Rate: 4,575 movies/sec | Elapsed: 3.06s
  Indexed: 15000 movies | Rate: 4,545 movies/sec | Elapsed: 3.30s
  Indexed: 16000 movies | Rate: 4,530 movies/sec | Elapsed: 3.53s
  Indexed: 17000 movies | Rate: 4,496 movies/sec | Elapsed: 3.78s
  Indexed: 18000 movies | Rate: 4,472 movies/sec | Elapsed: 4.02s
  Indexed: 19000 movies | Rate: 4,454 movies/sec | Elapsed: 4.27s
  Indexed: 20000 movies | Rate: 4,437 movies/sec | Elapsed: 4.51s
  Indexed: 21000 movies | Rate: 4,416 movies/sec | Elapsed: 4.76s
  Indexed: 22000 movies | Rate: 4,414 movies/sec | Elapsed: 4.98s
  Indexed: 23000 movies | Rate: 4,381 movies/sec | Elapsed: 5.25s
  Indexed: 24000 movies | Rate: 4,384 movies/sec | Elapsed: 5.47s
  Indexed: 25000 movies | Rate: 4,387 movies/sec | Elapsed: 5.70s
  Indexed: 26000 movies | Rate: 4,381 movies/sec | Elapsed: 5.94s
  Indexed: 27000 movies | Rate: 4,389 movies/sec | Elapsed: 6.15s
  Indexed: 28000 movies | Rate: 4,391 movies/sec | Elapsed: 6.38s
  Indexed: 29000 movies | Rate: 4,386 movies/sec | Elapsed: 6.61s
  Indexed: 30000 movies | Rate: 4,390 movies/sec | Elapsed: 6.83s
  Indexed: 31000 movies | Rate: 4,407 movies/sec | Elapsed: 7.03s
  Indexed: 31944 movies | Rate: 4,421 movies/sec | Elapsed: 7.22s

Benchmark Results
=================
Total movies processed: 31944
Successfully indexed: 31944
Errors: 0
Total time: 7.2711 seconds
Loading time: 0.0462 seconds
Indexing time: 7.2249 seconds
Average indexing rate: 4,421.38 movies/second
Memory used: 58.69 MB
Peak memory: 60.17 MB
```

## Testing Search Functionality

### Standard Search (fuzzy: OFF)

```text
Query: 'star wars' (took 5.90 ms)
Results found: 5 (Total hits: 925)
  1. Star Wars (Score: 100.0000)
  2. Star Wars: Deleted Magic (Score: 31.2000)
  3. Star Wars: The Legacy Revealed (Score: 29.8000)
  4. Star Wars: Greatest Moments (Score: 29.4000)
  5. Robot Chicken: Star Wars Episode III (Score: 28.7000)

Query: 'action' (took 10.92 ms)
Results found: 5 (Total hits: 5728)
  1. Action Figures (Score: 100.0000)
  2. Back in Action (Score: 98.1000)
  3. Missing in Action 2: The Beginning (Score: 97.4000)
  4. Action Jackson (Score: 96.0000)
  5. Last Action Hero (Score: 94.6000)

Query: 'drama crime' (took 21.40 ms)
Results found: 5 (Total hits: 14105)
  1. Cement (Score: 100.0000)
  2. Payback (Score: 89.1000)
  3. Zarra's Law (Score: 87.6000)
  4. Low Winter Sun (Score: 86.9000)
  5. London Boulevard (Score: 84.9000)

Query: 'nemo' (took 0.18 ms)
Results found: 5 (Total hits: 9)
  1. Finding Nemo (Score: 100.0000)
  2. Captain Nemo and the Underwater City (Score: 95.4000)
  3. Little Nemo: Adventures in Slumberland (Score: 69.4000)
  4. 20,000 Leagues Under the Sea (Score: 30.8000)
  5. Mr. Nobody (Score: 30.8000)

Query: 'matrix' (took 0.21 ms)
Results found: 5 (Total hits: 20)
  1. The Matrix Revisited (Score: 100.0000)
  2. Sexual Matrix (Score: 94.5000)
  3. The Matrix (Score: 90.1000)
  4. The Matrix Recalibrated (Score: 78.9000)
  5. The Animatrix (Score: 76.5000)

Query: 'Anakin Skywalker' (took 0.32 ms)
Results found: 5 (Total hits: 18)
  1. Star Wars: Episode III - Revenge of the Sith (Score: 100.0000)
  2. Star Wars: Episode I - The Phantom Menace (Score: 96.0000)
  3. Star Wars: Episode II - Attack of the Clones (Score: 96.0000)
  4. The Story of Star Wars (Score: 85.1000)
  5. Star Wars: The Rise of Skywalker (Score: 11.4000)
```


### Standard Search (fuzzy: ON - Trigram)

```text
Query: 'star wars' (took 73.05 ms)
Results found: 5 (Total hits: 925)
  1. Star Wars (Score: 100.0000)
  2. Star Wars: Deleted Magic (Score: 31.2000)
  3. Star Wars: The Legacy Revealed (Score: 29.8000)
  4. Star Wars: Greatest Moments (Score: 29.4000)
  5. Robot Chicken: Star Wars Episode III (Score: 28.7000)

Query: 'action' (took 25.77 ms)
Results found: 5 (Total hits: 5937)
  1. Chain Reaction (Score: 85.0000)
  2. Attraction (Score: 80.5000)
  3. Attraction (Score: 76.6000)
  4. Laws of Attraction (Score: 60.7000)
  5. Fatal Attraction (Score: 56.4000)

Query: 'drama crime' (took 49.41 ms)
Results found: 5 (Total hits: 14145)
  1. The Krays (Score: 85.0000)
  2. Like Mother Like Son: The Strange Story of Sante and Kenny Kimes (Score: 85.0000)
  3. Cement (Score: 80.4000)
  4. The Outrage (Score: 69.3000)
  5. The Maker (Score: 68.9000)

Query: 'nemo' (took 13.03 ms)
Results found: 5 (Total hits: 9)
  1. Finding Nemo (Score: 100.0000)
  2. Captain Nemo and the Underwater City (Score: 95.4000)
  3. Little Nemo: Adventures in Slumberland (Score: 69.4000)
  4. 20,000 Leagues Under the Sea (Score: 30.8000)
  5. Mr. Nobody (Score: 30.8000)

Query: 'matrix' (took 14.24 ms)
Results found: 5 (Total hits: 20)
  1. The Matrix Revisited (Score: 100.0000)
  2. Sexual Matrix (Score: 94.5000)
  3. The Matrix (Score: 90.1000)
  4. The Matrix Recalibrated (Score: 78.9000)
  5. The Animatrix (Score: 76.5000)

Query: 'Anakin Skywalker' (took 30.50 ms)
Results found: 5 (Total hits: 18)
  1. Star Wars: Episode III - Revenge of the Sith (Score: 100.0000)
  2. Star Wars: Episode I - The Phantom Menace (Score: 96.0000)
  3. Star Wars: Episode II - Attack of the Clones (Score: 96.0000)
  4. The Story of Star Wars (Score: 85.1000)
  5. Star Wars: The Rise of Skywalker (Score: 11.4000)
```

### Fuzzy Search (fuzzy: ON - Trigram)

```text
Query: 'Amakin Dkywalker' (took 30.71 ms)
Results found: 5 (Total hits: 16)
[Looking for: 'Anakin Skywalker']
  1. Star Wars: The Rise of Skywalker (Score: 57.1000)
  2. The Story of Star Wars (Score: 25.8000)
  3. Star Wars: Episode III - Revenge of the Sith (Score: 24.2000)
  4. Fanboys (Score: 24.2000)
  5. Star Wars: Episode I - The Phantom Menace (Score: 23.2000)

Query: 'Skywaker' (took 15.97 ms)
Results found: 5 (Total hits: 16)
[Looking for: 'Skywalker']
  1. Star Wars: The Rise of Skywalker (Score: 61.5000)
     *** Found expected result! ***
  2. The Story of Star Wars (Score: 27.8000)
  3. Star Wars: Episode III - Revenge of the Sith (Score: 26.1000)
  4. Fanboys (Score: 26.1000)
  5. Star Wars: Episode I - The Phantom Menace (Score: 25.0000)

Query: 'Star Wrs' (took 16.27 ms)
Results found: 5 (Total hits: 838)
[Looking for: 'Star Wars']
  1. Star Trek: Evolutions (Score: 100.0000)
  2. The Star of Bethlehem (Score: 96.0000)
  3. Robot Chicken: Star Wars Episode III (Score: 95.8000)
     *** Found expected result! ***
  4. Big Star: Nothing Can Hurt Me (Score: 95.4000)
  5. Star Wars: The Legacy Revealed (Score: 92.2000)
     *** Found expected result! ***

Query: 'The Godfater' (took 15.99 ms)
Results found: 5 (Total hits: 22)
[Looking for: 'The Godfather']
  1. The Godfather Legacy (Score: 61.5000)
     *** Found expected result! ***
  2. Herschell Gordon Lewis: The Godfather of Gore (Score: 59.8000)
     *** Found expected result! ***
  3. Bonanno: A Godfather's Story (Score: 57.6000)
  4. The Godfather Trilogy: 1901-1980 (Score: 55.3000)
     *** Found expected result! ***
  5. The Godfather: Part III (Score: 54.0000)
     *** Found expected result! ***

Query: 'Inceptionn' (took 34.25 ms)
Results found: 0 (Total hits: 0)
[Looking for: 'Inception']

Query: 'The Dark Knigh' (took 29.79 ms)
Results found: 5 (Total hits: 792)
[Looking for: 'The Dark Knight']
  1. The Dark Knight Rises (Score: 85.0000)
     *** Found expected result! ***
  2. The Dark Knight (Score: 64.8000)
     *** Found expected result! ***
  3. Legends of the Dark Knight: The History of Batman (Score: 59.9000)
     *** Found expected result! ***
  4. Batman: The Dark Knight Returns, Part 2 (Score: 58.7000)
     *** Found expected result! ***
  5. Batman: The Dark Knight Returns, Part 1 (Score: 55.7000)
     *** Found expected result! ***

Query: 'Pulp Fictin' (took 33.92 ms)
Results found: 5 (Total hits: 2939)
[Looking for: 'Pulp Fiction']
  1. Pulp Fiction (Score: 85.0000)
     *** Found expected result! ***
  2. Plump Fiction (Score: 38.3000)
  3. Pulp (Score: 34.9000)
  4. The Magnificent One (Score: 33.9000)
  5. City Rats (Score: 32.1000)

Query: 'Forrest Gump' (took 30.23 ms)
Results found: 5 (Total hits: 198)
[Looking for: 'Forrest Gump']
  1. Forrest Gump (Score: 100.0000)
     *** Found expected result! ***
  2. UFC 86: Jackson vs. Griffin (Score: 0.4000)
  3. The Old Man & the Gun (Score: 0.4000)
  4. Sex Files: Alien Erotica II (Score: 0.3000)
  5. Deadly Honeymoon (Score: 0.3000)

Query: 'The Shawshank Redemtion' (took 33.79 ms)
Results found: 5 (Total hits: 109)
[Looking for: 'The Shawshank Redemption']
  1. The Shawshank Redemption (Score: 85.0000)
     *** Found expected result! ***
  2. Tales from the Script (Score: 16.9000)
  3. Redemption (Score: 3.5000)
  4. UFC 97: Redemption (Score: 2.5000)
  5. Redemption: The Stan Tookie Williams Story (Score: 1.9000)

Query: 'Interstelar' (took 18.10 ms)
Results found: 5 (Total hits: 13)
[Looking for: 'Interstellar']
  1. Interstellar (Score: 80.0000)
     *** Found expected result! ***
  2. Lolita from Interstellar Space (Score: 41.3000)
     *** Found expected result! ***
  3. Interstellar: Nolan's Odyssey (Score: 39.0000)
     *** Found expected result! ***
  4. Interstellar Wars (Score: 21.1000)
     *** Found expected result! ***
  5. Suburban Commando (Score: 14.2000)

Query: 'Gladiatorr' (took 17.26 ms)
Results found: 5 (Total hits: 17)
[Looking for: 'Gladiator']
  1. Gladiator (Score: 76.9000)
     *** Found expected result! ***
  2. Gladiator (Score: 69.9000)
     *** Found expected result! ***
  3. Gladiator Eroticvs: The Lesbian Warriors (Score: 43.6000)
     *** Found expected result! ***
  4. Gladiator Cop (Score: 41.7000)
     *** Found expected result! ***
  5. Gladiators of Rome (Score: 41.1000)
     *** Found expected result! ***

Query: 'Luck Skywalker' (took 32.55 ms)
Results found: 5 (Total hits: 191)
[Looking for: 'Luke Skywalker']
  1. Star Wars: The Rise of Skywalker (Score: 100.0000)
  2. Pure Luck (Score: 99.1000)
  3. Better Luck Tomorrow (Score: 77.7000)
  4. The Joy Luck Club (Score: 77.7000)
  5. Good Night, and Good Luck. (Score: 76.4000)

Query: 'Drath Vader' (took 27.76 ms)
Results found: 5 (Total hits: 10)
[Looking for: 'Darth Vader']
  1. Star Wars Rebels: The Siege of Lothal (Score: 100.0000)
  2. WWE No Way Out of Texas: In Your House (Score: 64.7000)
  3. LEGO Star Wars: The Empire Strikes Out (Score: 63.9000)
  4. WWE WrestleMania 13 (Score: 62.6000)
  5. The Empire Strikes Back (Score: 60.2000)
```