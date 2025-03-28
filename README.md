![Screenshot 2025-03-28 190737](https://github.com/user-attachments/assets/8932d5cc-e485-426a-bda8-08d8576e30cc)

 ![Screenshot 2025-03-28 191036](https://github.com/user-attachments/assets/10e3f312-5043-4015-bac4-046c3002d025)

 🌟 Garbage Collection Simulator
A web-based tool to visualize memory management and garbage collection algorithms. Built with PHP and Tailwind CSS, it helps students, developers, and educators understand memory allocation.

 ✨ Features
- Interactive Memory Visualization: Color-coded memory blocks, fragmentation display.
- Collection Strategies: Generational GC, Mark-and-Sweep, Reference Counting.
- Comprehensive Statistics: Memory usage metrics, performance data.
- Modern UI: Dark/light mode, responsive design, animated transitions.
- **User Controls**: Manual allocation, memory compaction, root reference management.

🛠️ Installation
    Prerequisites
- PHP 7.4+
- Web server (Apache/Nginx/PHP built-in server)
- Composer (optional for development)

   Setup
```bash
git clone https://github.com/yourusername/garbage-collection-simulator.git
cd garbage-collection-simulator
php -S localhost:8000  # Run built-in server
```
Visit **http://localhost:8000** in your browser.

   🚀 Usage
- **Allocate Objects**: Click "Allocate Small/Medium/Large Object"
- **Run Garbage Collection**: Collect by generation or full GC
- **Manage Memory**: Compact, free objects, mark roots
- Panels:
  - Memory Visualization: Shows current state
  - Statistics: Allocation and performance metrics
  - Actions: Interactive controls
  - Objects: Lists allocated objects

 💻 Technical Details
### Architecture
- **Frontend**: HTML, Tailwind CSS, Chart.js
- **Backend**: PHP (Object-oriented), Sessions for state management

### Key Components
- **MemoryManager**: Handles core memory operations, GC algorithms
- **GarbageCollectionSimulator**: Controls UI, user interactions

### Algorithms
- **Generational GC**: Objects categorized into young, middle, old
- **Mark-and-Sweep**: Mark phase (reachable objects), Sweep phase (reclaims memory)
- **Reference Counting**: Tracks object references, auto-collects unreferenced ones

## 🤝 Contributing
- **Report Issues**: Found a bug? Open an issue
- **Suggest Enhancements**: Feature requests are welcome
- **Submit Pull Requests**:
  1. Fork the repository
  2. Create a new branch
  3. Submit a PR with clear changes

### Development Setup
```bash
npm install -D tailwindcss
npx tailwindcss init
npx tailwindcss -i ./src/input.css -o ./dist/output.css --watch
```

## 📜 License
Licensed under the **DEEPAK KUMAR[SELF]**.  

## 📧 Contact
Your Name - dkvats57@gmail.com 
[Project Link]  - (https://github.com/Dksinghxd/garbage-collection-simulator)

