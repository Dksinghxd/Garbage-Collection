<?php
class MemoryManager {
    private $memoryPool = [];
    private $allocatedObjects = [];
    private $rootReferences = [];
    private $totalBlocks;
    private $gcStats = [
        'total_collections' => 0,
        'young_collections' => 0,
        'middle_collections' => 0,
        'old_collections' => 0,
        'total_objects_collected' => 0,
        'total_time' => 0
    ];
    
    public function __construct($totalBlocks = 256) {
        $this->totalBlocks = $totalBlocks;
        $this->memoryPool = array_fill(0, $totalBlocks, null);
    }
    
    public function allocate($object, $size, $references = []) {
        // Find contiguous free blocks
        $freeBlocks = $this->findFreeBlocks($size);
        if (count($freeBlocks) < $size) {
            // Try to collect garbage if we can't find space
            $this->collectGarbage('young');
            $freeBlocks = $this->findFreeBlocks($size);
            
            if (count($freeBlocks) < $size) {
                throw new Exception("Not enough memory to allocate $size blocks");
            }
        }
        
        // Create object entry
        $id = uniqid('obj_', true);
        $this->allocatedObjects[$id] = [
            'object' => $object,
            'blocks' => array_slice($freeBlocks, 0, $size),
            'generation' => 'young',
            'last_collection' => 0,
            'references' => $references
        ];
        
        // Mark blocks as allocated
        foreach ($this->allocatedObjects[$id]['blocks'] as $block) {
            $this->memoryPool[$block] = $id;
        }
        
        return $id;
    }
    
    private function findFreeBlocks($size) {
        $freeBlocks = [];
        $currentRun = 0;
        
        foreach ($this->memoryPool as $index => $content) {
            if ($content === null) {
                $currentRun++;
                $freeBlocks[] = $index;
                if ($currentRun >= $size) {
                    return $freeBlocks;
                }
            } else {
                $currentRun = 0;
                $freeBlocks = [];
            }
        }
        
        return $freeBlocks;
    }
    
    public function collectGarbage($generation = 'all') {
        $startTime = microtime(true);
        $collected = 0;
        
        if ($generation === 'all') {
            $collected = $this->markAndSweep();
            $this->gcStats['total_collections']++;
        } else {
            $collected = $this->collectGeneration($generation);
        }
        
        $this->gcStats['total_objects_collected'] += $collected;
        $this->gcStats['total_time'] += microtime(true) - $startTime;
        
        return $collected;
    }
    
    private function collectGeneration($generation) {
        $collected = 0;
        
        switch ($generation) {
            case 'young':
                $this->gcStats['young_collections']++;
                $collected = $this->sweepGeneration('young');
                break;
                
            case 'middle':
                $this->gcStats['middle_collections']++;
                $collected = $this->sweepGeneration('middle');
                break;
                
            case 'old':
                $this->gcStats['old_collections']++;
                $collected = $this->sweepGeneration('old');
                break;
        }
        
        $this->gcStats['total_collections']++;
        return $collected;
    }
    
    private function markAndSweep() {
        $marked = [];
        
        // Mark phase - start from roots
        foreach ($this->rootReferences as $rootId) {
            $this->mark($rootId, $marked);
        }
        
        // Sweep phase - collect unmarked objects
        $collected = 0;
        foreach ($this->allocatedObjects as $id => $object) {
            if (!in_array($id, $marked)) {
                $this->freeObject($id);
                $collected++;
            } else {
                // Promote surviving objects
                $this->promoteObject($id);
            }
        }
        
        return $collected;
    }
    
    private function mark($id, &$marked) {
        if (in_array($id, $marked) || !isset($this->allocatedObjects[$id])) {
            return;
        }
        
        $marked[] = $id;
        
        // Mark all referenced objects
        foreach ($this->allocatedObjects[$id]['references'] as $refId) {
            $this->mark($refId, $marked);
        }
    }
    
    private function sweepGeneration($generation) {
        $collected = 0;
        
        foreach ($this->allocatedObjects as $id => $object) {
            if ($object['generation'] === $generation) {
                $isReachable = $this->isReachable($id);
                
                if (!$isReachable) {
                    $this->freeObject($id);
                    $collected++;
                } else {
                    $this->promoteObject($id);
                }
            }
        }
        
        return $collected;
    }
    
    private function isReachable($id) {
        // Check if it's a root reference
        if (in_array($id, $this->rootReferences)) {
            return true;
        }
        
        // Check if any object references this one
        foreach ($this->allocatedObjects as $objId => $object) {
            if (in_array($id, $object['references'])) {
                return true;
            }
        }
        
        return false;
    }
    
    private function promoteObject($id) {
        $object = &$this->allocatedObjects[$id];
        $object['last_collection'] = $this->gcStats['total_collections'];
        
        // Promotion logic
        if ($object['generation'] === 'young' && $this->gcStats['total_collections'] - $object['last_collection'] > 1) {
            $object['generation'] = 'middle';
        } elseif ($object['generation'] === 'middle' && $this->gcStats['total_collections'] - $object['last_collection'] > 2) {
            $object['generation'] = 'old';
        }
    }
    
    private function freeObject($id) {
        if (!isset($this->allocatedObjects[$id])) {
            return;
        }
        
        // Free memory blocks
        foreach ($this->allocatedObjects[$id]['blocks'] as $block) {
            $this->memoryPool[$block] = null;
        }
        
        // Remove from allocated objects
        unset($this->allocatedObjects[$id]);
        
        // Remove from root references if present
        $rootIndex = array_search($id, $this->rootReferences);
        if ($rootIndex !== false) {
            unset($this->rootReferences[$rootIndex]);
        }
    }
    
    public function compactMemory() {
        $usedBlocks = [];
        $objectMap = [];
        
        // Gather all used blocks and their objects
        foreach ($this->allocatedObjects as $id => $object) {
            $usedBlocks = array_merge($usedBlocks, $object['blocks']);
            $objectMap[$id] = $object;
        }
        
        sort($usedBlocks);
        
        // Rebuild memory pool compacted
        $this->memoryPool = array_fill(0, $this->totalBlocks, null);
        $currentBlock = 0;
        
        foreach ($objectMap as $id => $object) {
            $size = count($object['blocks']);
            $newBlocks = range($currentBlock, $currentBlock + $size - 1);
            
            // Update object blocks
            $this->allocatedObjects[$id]['blocks'] = $newBlocks;
            
            // Update memory pool
            foreach ($newBlocks as $block) {
                $this->memoryPool[$block] = $id;
            }
            
            $currentBlock += $size;
        }
    }
    
    public function addRootReference($id) {
        if (!in_array($id, $this->rootReferences)) {
            $this->rootReferences[] = $id;
        }
    }
    
    public function removeRootReference($id) {
        $index = array_search($id, $this->rootReferences);
        if ($index !== false) {
            unset($this->rootReferences[$index]);
        }
    }
    
    public function getMemoryStats() {
        $allocatedBlocks = 0;
        $youngObjects = 0;
        $middleObjects = 0;
        $oldObjects = 0;
        
        foreach ($this->allocatedObjects as $object) {
            $allocatedBlocks += count($object['blocks']);
            
            switch ($object['generation']) {
                case 'young': $youngObjects++; break;
                case 'middle': $middleObjects++; break;
                case 'old': $oldObjects++; break;
            }
        }
        
        // Calculate fragmentation (simplified)
        $freeBlocks = $this->totalBlocks - $allocatedBlocks;
        $largestFreeBlock = $this->findLargestFreeBlock();
        $fragmentation = $largestFreeBlock > 0 ? 1 - ($largestFreeBlock / $freeBlocks) : 0;
        
        return [
            'total_blocks' => $this->totalBlocks,
            'allocated_blocks' => $allocatedBlocks,
            'free_blocks' => $freeBlocks,
            'fragmentation' => $fragmentation,
            'young_objects' => $youngObjects,
            'middle_objects' => $middleObjects,
            'old_objects' => $oldObjects,
            'gc_stats' => $this->gcStats
        ];
    }
    
    private function findLargestFreeBlock() {
        $maxRun = 0;
        $currentRun = 0;
        
        foreach ($this->memoryPool as $block) {
            if ($block === null) {
                $currentRun++;
                $maxRun = max($maxRun, $currentRun);
            } else {
                $currentRun = 0;
            }
        }
        
        return $maxRun;
    }
    
    public function getMemoryPool() {
        return $this->memoryPool;
    }
    
    public function getAllocatedObjects() {
        return $this->allocatedObjects;
    }
    
    public function getObjectReferences($id) {
        if (!isset($this->allocatedObjects[$id])) {
            return ['reference_count' => 0, 'references' => []];
        }
        
        return [
            'reference_count' => count($this->allocatedObjects[$id]['references']),
            'references' => $this->allocatedObjects[$id]['references']
        ];
    }
    
    public function getObjectGeneration($id) {
        return $this->allocatedObjects[$id]['generation'] ?? 'unknown';
    }
    
    public function getObjectBlocks($id) {
        return $this->allocatedObjects[$id]['blocks'] ?? [];
    }
}

class GarbageCollectionSimulator {
    private $memoryManager;
    
    public function __construct() {
        $this->memoryManager = new MemoryManager(256);
        session_start();
        
        if (!isset($_SESSION['objects'])) {
            $_SESSION['objects'] = [];
        }
        
        if (!isset($_SESSION['roots'])) {
            $_SESSION['roots'] = [];
        }
    }
    
    private function getObjectColorClasses($block) {
        foreach ($this->memoryManager->getAllocatedObjects() as $id => $obj) {
            if (in_array($block, $obj['blocks'])) {
                switch ($obj['generation']) {
                    case 'young': return 'bg-red-400 dark:bg-red-600';
                    case 'middle': return 'bg-yellow-400 dark:bg-yellow-600';
                    case 'old': return 'bg-green-400 dark:bg-green-600';
                }
            }
        }
        return 'bg-gray-300 dark:bg-gray-600';
    }
    
    public function run() {
        $action = $_GET['action'] ?? 'status';
        
        switch ($action) {
            case 'allocate':
                $size = $_GET['size'] ?? 1;
                $refCount = rand(0, 3);
                $references = [];
                
                if ($refCount > 0 && !empty($_SESSION['objects'])) {
                    $existingObjects = array_keys($_SESSION['objects']);
                    shuffle($existingObjects);
                    $references = array_slice($existingObjects, 0, $refCount);
                }
                
                $id = $this->memoryManager->allocate($this->generateRandomObject(), $size, $references);
                $_SESSION['objects'][$id] = true;
                
                if (rand(0, 5) === 0) {
                    $this->memoryManager->addRootReference($id);
                    $_SESSION['roots'][$id] = true;
                }
                break;
                
            case 'collect':
                $generation = $_GET['generation'] ?? 'all';
                $result = $this->memoryManager->collectGarbage($generation);
                break;
                
            case 'free':
                $id = $_GET['id'] ?? null;
                if ($id && isset($_SESSION['objects'][$id])) {
                    unset($_SESSION['objects'][$id]);
                    if (isset($_SESSION['roots'][$id])) {
                        $this->memoryManager->removeRootReference($id);
                        unset($_SESSION['roots'][$id]);
                    }
                }
                break;
                
            case 'compact':
                $this->memoryManager->compactMemory();
                break;
                
            case 'add_root':
                $id = $_GET['id'] ?? null;
                if ($id && isset($_SESSION['objects'][$id])) {
                    $this->memoryManager->addRootReference($id);
                    $_SESSION['roots'][$id] = true;
                }
                break;
                
            case 'remove_root':
                $id = $_GET['id'] ?? null;
                if ($id && isset($_SESSION['roots'][$id])) {
                    $this->memoryManager->removeRootReference($id);
                    unset($_SESSION['roots'][$id]);
                }
                break;
        }
        
        $this->displayInterface();
    }
    
    private function generateRandomObject() {
        $types = ['string', 'array', 'object', 'number'];
        $type = $types[array_rand($types)];
        
        switch ($type) {
            case 'string': return bin2hex(random_bytes(10));
            case 'array': return range(1, rand(1, 10));
            case 'object': return (object)['value' => rand(1, 100)];
            case 'number': return rand(1, 1000);
        }
    }
    
    private function displayInterface() {
        $stats = $this->memoryManager->getMemoryStats();
        $objects = $_SESSION['objects'] ?? [];
        $roots = $_SESSION['roots'] ?? [];
        
        $chartData = [
            'labels' => ['Young', 'Middle', 'Old'],
            'collections' => [
                $stats['gc_stats']['young_collections'],
                $stats['gc_stats']['middle_collections'],
                $stats['gc_stats']['old_collections']
            ],
            'objects_collected' => [
                $stats['gc_stats']['total_objects_collected'] / max(1, $stats['gc_stats']['total_collections'])
            ]
        ];
        ?>
        <!DOCTYPE html>
        <html lang="en" class="scroll-smooth">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Garbage Collection Simulator</title>
            <script src="https://cdn.tailwindcss.com"></script>
            <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
            <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
            <script>
                tailwind.config = {
                    darkMode: 'class',
                    theme: {
                        extend: {
                            fontFamily: {
                                sans: ['Inter', 'sans-serif'],
                            },
                            colors: {
                                primary: {
                                    50: '#f0f9ff',
                                    100: '#e0f2fe',
                                    200: '#bae6fd',
                                    300: '#7dd3fc',
                                    400: '#38bdf8',
                                    500: '#0ea5e9',
                                    600: '#0284c7',
                                    700: '#0369a1',
                                    800: '#075985',
                                    900: '#0c4a6e',
                                },
                                secondary: {
                                    50: '#f5f3ff',
                                    100: '#ede9fe',
                                    200: '#ddd6fe',
                                    300: '#c4b5fd',
                                    400: '#a78bfa',
                                    500: '#8b5cf6',
                                    600: '#7c3aed',
                                    700: '#6d28d9',
                                    800: '#5b21b6',
                                    900: '#4c1d95',
                                }
                            },
                            animation: {
                                'fade-in': 'fadeIn 0.3s ease-in-out',
                                'slide-up': 'slideUp 0.3s ease-out',
                            },
                            keyframes: {
                                fadeIn: {
                                    '0%': { opacity: '0' },
                                    '100%': { opacity: '1' },
                                },
                                slideUp: {
                                    '0%': { transform: 'translateY(10px)', opacity: '0' },
                                    '100%': { transform: 'translateY(0)', opacity: '1' },
                                }
                            }
                        }
                    }
                }
            </script>
            <style type="text/css">
                .gradient-bg {
                    background: linear-gradient(135deg, rgba(14,165,233,0.1) 0%, rgba(139,92,246,0.1) 100%);
                }
                .dark .gradient-bg {
                    background: linear-gradient(135deg, rgba(14,165,233,0.2) 0%, rgba(139,92,246,0.2) 100%);
                }
                .memory-block {
                    transition: all 0.2s ease;
                }
                .memory-block:hover {
                    transform: scale(1.05);
                    z-index: 10;
                }
            </style>
        </head>
        <body class="bg-gray-50 text-gray-800 dark:bg-gray-900 dark:text-gray-100 transition-colors duration-200">
            <div class="container mx-auto px-4 py-8 max-w-7xl">
                <!-- Header with theme toggle -->
                <div class="flex justify-between items-center mb-8">
                    <h1 class="text-3xl font-bold bg-gradient-to-r from-primary-600 to-secondary-600 bg-clip-text text-transparent">
                        Garbage Collection Simulator
                    </h1>
                    <button id="themeToggle" class="p-2 rounded-full bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
                        </svg>
                    </button>
                </div>

                <!-- Memory Statistics Panel -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden mb-8 animate-fade-in gradient-bg">
                    <div class="p-6">
                        <h2 class="text-xl font-semibold mb-4 text-primary-700 dark:text-primary-400">Memory Statistics</h2>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <!-- Memory Blocks Card -->
                            <div class="bg-white dark:bg-gray-700 p-4 rounded-lg shadow-sm border border-gray-100 dark:border-gray-600 hover:shadow-md transition-shadow">
                                <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">Memory Blocks</h3>
                                <div class="space-y-1">
                                    <p class="text-gray-700 dark:text-gray-200">Total: <span class="font-semibold"><?= $stats['total_blocks'] ?></span></p>
                                    <p class="text-gray-700 dark:text-gray-200">Allocated: <span class="font-semibold text-primary-600 dark:text-primary-400"><?= $stats['allocated_blocks'] ?></span></p>
                                    <p class="text-gray-700 dark:text-gray-200">Free: <span class="font-semibold text-green-600 dark:text-green-400"><?= $stats['free_blocks'] ?></span></p>
                                    <p class="text-gray-700 dark:text-gray-200">Fragmentation: <span class="font-semibold <?= $stats['fragmentation'] > 0.3 ? 'text-red-600 dark:text-red-400' : 'text-gray-700 dark:text-gray-200' ?>"><?= round($stats['fragmentation'] * 100) ?>%</span></p>
                                </div>
                            </div>
                            
                            <!-- Generations Card -->
                            <div class="bg-white dark:bg-gray-700 p-4 rounded-lg shadow-sm border border-gray-100 dark:border-gray-600 hover:shadow-md transition-shadow">
                                <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">Generations</h3>
                                <div class="space-y-1">
                                    <p class="text-gray-700 dark:text-gray-200">Young: <span class="font-semibold text-red-500 dark:text-red-400"><?= $stats['young_objects'] ?></span></p>
                                    <p class="text-gray-700 dark:text-gray-200">Middle: <span class="font-semibold text-yellow-500 dark:text-yellow-400"><?= $stats['middle_objects'] ?></span></p>
                                    <p class="text-gray-700 dark:text-gray-200">Old: <span class="font-semibold text-green-500 dark:text-green-400"><?= $stats['old_objects'] ?></span></p>
                                </div>
                            </div>
                            
                            <!-- GC Performance Card -->
                            <div class="bg-white dark:bg-gray-700 p-4 rounded-lg shadow-sm border border-gray-100 dark:border-gray-600 hover:shadow-md transition-shadow">
                                <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">GC Performance</h3>
                                <div class="space-y-1">
                                    <p class="text-gray-700 dark:text-gray-200">Total Collections: <span class="font-semibold"><?= $stats['gc_stats']['total_collections'] ?></span></p>
                                    <p class="text-gray-700 dark:text-gray-200">Objects Collected: <span class="font-semibold"><?= $stats['gc_stats']['total_objects_collected'] ?></span></p>
                                    <p class="text-gray-700 dark:text-gray-200">Total GC Time: <span class="font-semibold"><?= round($stats['gc_stats']['total_time'] * 1000, 2) ?>ms</span></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Memory Visualization Panel -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden mb-8 animate-fade-in">
                    <div class="p-6">
                        <h2 class="text-xl font-semibold mb-4 text-primary-700 dark:text-primary-400">Memory Visualization</h2>
                        <div class="memory-vis bg-gray-100 dark:bg-gray-700 p-4 rounded-lg overflow-x-auto">
                            <div class="flex h-8 mb-2">
                                <?php foreach ($this->memoryManager->getMemoryPool() as $block => $content): ?>
                                    <div class="memory-block h-full <?= $content === null ? 'bg-gray-300 dark:bg-gray-600' : $this->getObjectColorClasses($block) ?> border border-gray-200 dark:border-gray-600" style="width: <?= 100 / count($this->memoryManager->getMemoryPool()) ?>%"></div>
                                <?php endforeach; ?>
                            </div>
                            <div class="flex justify-between text-xs text-gray-500 dark:text-gray-400">
                                <span class="text-red-500 dark:text-red-400">Young Generation</span>
                                <span class="text-yellow-500 dark:text-yellow-400">Middle Generation</span>
                                <span class="text-green-500 dark:text-green-400">Old Generation</span>
                                <span class="text-gray-500 dark:text-gray-400">Free Memory</span>
                            </div>
                        </div>
                        <button onclick="location.href='?action=compact'" class="mt-4 px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white rounded-lg shadow-md hover:shadow-lg transition-all transform hover:-translate-y-0.5">
                            Compact Memory
                        </button>
                    </div>
                </div>

                <!-- GC Performance Charts Panel -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden mb-8 animate-fade-in">
                    <div class="p-6">
                        <h2 class="text-xl font-semibold mb-4 text-primary-700 dark:text-primary-400">GC Performance Charts</h2>
                        <div class="chart-container grid grid-cols-1 lg:grid-cols-2 gap-6">
                            <div class="chart bg-white dark:bg-gray-700 p-4 rounded-lg shadow-sm border border-gray-100 dark:border-gray-600">
                                <canvas id="collectionsChart" width="400" height="200"></canvas>
                            </div>
                            <div class="chart bg-white dark:bg-gray-700 p-4 rounded-lg shadow-sm border border-gray-100 dark:border-gray-600">
                                <canvas id="objectsChart" width="400" height="200"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Actions Panel -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden mb-8 animate-fade-in">
                    <div class="p-6">
                        <h2 class="text-xl font-semibold mb-4 text-primary-700 dark:text-primary-400">Actions</h2>
                        <div class="actions flex flex-wrap gap-3">
                            <a href="?action=allocate&size=1" class="px-4 py-2 bg-primary-100 dark:bg-gray-700 hover:bg-primary-200 dark:hover:bg-gray-600 text-primary-700 dark:text-primary-300 rounded-lg shadow-sm hover:shadow-md transition-all border border-primary-200 dark:border-gray-600">
                                Allocate Small Object
                            </a>
                            <a href="?action=allocate&size=5" class="px-4 py-2 bg-primary-100 dark:bg-gray-700 hover:bg-primary-200 dark:hover:bg-gray-600 text-primary-700 dark:text-primary-300 rounded-lg shadow-sm hover:shadow-md transition-all border border-primary-200 dark:border-gray-600">
                                Allocate Medium Object
                            </a>
                            <a href="?action=allocate&size=10" class="px-4 py-2 bg-primary-100 dark:bg-gray-700 hover:bg-primary-200 dark:hover:bg-gray-600 text-primary-700 dark:text-primary-300 rounded-lg shadow-sm hover:shadow-md transition-all border border-primary-200 dark:border-gray-600">
                                Allocate Large Object
                            </a>
                            <a href="?action=collect&generation=young" class="px-4 py-2 bg-red-100 dark:bg-gray-700 hover:bg-red-200 dark:hover:bg-gray-600 text-red-700 dark:text-red-300 rounded-lg shadow-sm hover:shadow-md transition-all border border-red-200 dark:border-gray-600">
                                Collect Young Gen
                            </a>
                            <a href="?action=collect&generation=middle" class="px-4 py-2 bg-yellow-100 dark:bg-gray-700 hover:bg-yellow-200 dark:hover:bg-gray-600 text-yellow-700 dark:text-yellow-300 rounded-lg shadow-sm hover:shadow-md transition-all border border-yellow-200 dark:border-gray-600">
                                Collect Middle Gen
                            </a>
                            <a href="?action=collect&generation=old" class="px-4 py-2 bg-green-100 dark:bg-gray-700 hover:bg-green-200 dark:hover:bg-gray-600 text-green-700 dark:text-green-300 rounded-lg shadow-sm hover:shadow-md transition-all border border-green-200 dark:border-gray-600">
                                Collect Old Gen
                            </a>
                            <a href="?action=collect" class="px-4 py-2 bg-secondary-100 dark:bg-gray-700 hover:bg-secondary-200 dark:hover:bg-gray-600 text-secondary-700 dark:text-secondary-300 rounded-lg shadow-sm hover:shadow-md transition-all border border-secondary-200 dark:border-gray-600">
                                Full GC
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Allocated Objects Panel -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden mb-8 animate-fade-in">
                    <div class="p-6">
                        <h2 class="text-xl font-semibold mb-4 text-primary-700 dark:text-primary-400">Allocated Objects</h2>
                        <div class="object-list max-h-80 overflow-y-auto bg-gray-50 dark:bg-gray-700 rounded-lg border border-gray-200 dark:border-gray-600 p-3">
                            <?php foreach ($objects as $id => $_): ?>
                                <?php $refInfo = $this->memoryManager->getObjectReferences($id); ?>
                                <div class="object-item mb-2 p-3 rounded-lg bg-white dark:bg-gray-600 shadow-xs hover:shadow-sm transition-shadow <?= isset($roots[$id]) ? 'border-l-4 border-red-500 bg-red-50 dark:bg-gray-800' : 'border-l-4 border-transparent' ?>">
                                    <div class="flex justify-between items-center">
                                        <div>
                                            <span class="font-medium text-gray-700 dark:text-gray-200">Object <?= substr($id, -6) ?></span>
                                            <span class="text-xs ml-2 px-2 py-1 rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                                                <?= $this->memoryManager->getObjectGeneration($id) ?>
                                            </span>
                                        </div>
                                        <div class="flex space-x-2">
                                            <?php if (isset($roots[$id])): ?>
                                                <a href="?action=remove_root&id=<?= $id ?>" class="text-xs px-2 py-1 bg-red-100 hover:bg-red-200 dark:bg-red-900/30 dark:hover:bg-red-900/50 text-red-700 dark:text-red-300 rounded-full transition-colors">
                                                    Remove Root
                                                </a>
                                            <?php else: ?>
                                                <a href="?action=add_root&id=<?= $id ?>" class="text-xs px-2 py-1 bg-green-100 hover:bg-green-200 dark:bg-green-900/30 dark:hover:bg-green-900/50 text-green-700 dark:text-green-300 rounded-full transition-colors">
                                                    Make Root
                                                </a>
                                            <?php endif; ?>
                                            <a href="?action=free&id=<?= $id ?>" class="text-xs px-2 py-1 bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 rounded-full transition-colors">
                                                Free
                                            </a>
                                        </div>
                                    </div>
                                    <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                        Size: <?= count($this->memoryManager->getObjectBlocks($id)) ?> blocks • 
                                        Refs: <?= $refInfo['reference_count'] ?>
                                    </div>
                                    <?php if (!empty($refInfo['references'])): ?>
                                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            References: <?= implode(', ', array_map(function($r) { return substr($r, -6); }, $refInfo['references'])) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                // Theme toggle functionality
                const themeToggle = document.getElementById('themeToggle');
                const html = document.documentElement;
                
                themeToggle.addEventListener('click', () => {
                    html.classList.toggle('dark');
                    localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light');
                    updateCharts();
                });
                
                // Set initial theme based on user preference or localStorage
                if (localStorage.getItem('theme') === 'dark' || (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                    html.classList.add('dark');
                }

                // Charts
                let collectionsChart, objectsChart;
                
                function updateCharts() {
                    const isDark = document.documentElement.classList.contains('dark');
                    const textColor = isDark ? '#e5e7eb' : '#374151';
                    const gridColor = isDark ? 'rgba(229, 231, 235, 0.1)' : 'rgba(209, 213, 219, 0.5)';
                    
                    if (collectionsChart) {
                        collectionsChart.options.scales.x.ticks.color = textColor;
                        collectionsChart.options.scales.y.ticks.color = textColor;
                        collectionsChart.options.scales.x.grid.color = gridColor;
                        collectionsChart.options.scales.y.grid.color = gridColor;
                        collectionsChart.options.plugins.legend.labels.color = textColor;
                        collectionsChart.update();
                    }
                    
                    if (objectsChart) {
                        objectsChart.options.scales.x.ticks.color = textColor;
                        objectsChart.options.scales.y.ticks.color = textColor;
                        objectsChart.options.scales.x.grid.color = gridColor;
                        objectsChart.options.scales.y.grid.color = gridColor;
                        objectsChart.options.plugins.legend.labels.color = textColor;
                        objectsChart.update();
                    }
                }
                
                document.addEventListener('DOMContentLoaded', function() {
                    const collectionsCtx = document.getElementById('collectionsChart').getContext('2d');
                    const objectsCtx = document.getElementById('objectsChart').getContext('2d');
                    
                    const isDark = document.documentElement.classList.contains('dark');
                    const textColor = isDark ? '#e5e7eb' : '#374151';
                    const gridColor = isDark ? 'rgba(229, 231, 235, 0.1)' : 'rgba(209, 213, 219, 0.5)';
                    
                    collectionsChart = new Chart(collectionsCtx, {
                        type: 'bar',
                        data: {
                            labels: <?= json_encode($chartData['labels']) ?>,
                            datasets: [{
                                label: 'Collections by Generation',
                                data: <?= json_encode($chartData['collections']) ?>,
                                backgroundColor: [
                                    'rgba(239, 68, 68, 0.7)',
                                    'rgba(234, 179, 8, 0.7)',
                                    'rgba(16, 185, 129, 0.7)'
                                ],
                                borderColor: [
                                    'rgba(239, 68, 68, 1)',
                                    'rgba(234, 179, 8, 1)',
                                    'rgba(16, 185, 129, 1)'
                                ],
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            plugins: {
                                legend: {
                                    labels: {
                                        color: textColor
                                    }
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    grid: {
                                        color: gridColor
                                    },
                                    ticks: {
                                        color: textColor
                                    }
                                },
                                x: {
                                    grid: {
                                        color: gridColor
                                    },
                                    ticks: {
                                        color: textColor
                                    }
                                }
                            }
                        }
                    });
                    
                    objectsChart = new Chart(objectsCtx, {
                        type: 'line',
                        data: {
                            labels: ['Average Objects Collected'],
                            datasets: [{
                                label: 'Objects Collected per GC',
                                data: <?= json_encode($chartData['objects_collected']) ?>,
                                backgroundColor: 'rgba(14, 165, 233, 0.2)',
                                borderColor: 'rgba(14, 165, 233, 1)',
                                borderWidth: 2,
                                tension: 0.1,
                                pointBackgroundColor: 'rgba(14, 165, 233, 1)',
                                pointRadius: 5,
                                pointHoverRadius: 7
                            }]
                        },
                        options: {
                            responsive: true,
                            plugins: {
                                legend: {
                                    labels: {
                                        color: textColor
                                    }
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    grid: {
                                        color: gridColor
                                    },
                                    ticks: {
                                        color: textColor
                                    }
                                },
                                x: {
                                    grid: {
                                        color: gridColor
                                    },
                                    ticks: {
                                        color: textColor
                                    }
                                }
                            }
                        }
                    });
                });
            </script>
        </body>
        </html>
        <?php
    }
}

// Initialize and run the simulator
$simulator = new GarbageCollectionSimulator();
$simulator->run();
?>