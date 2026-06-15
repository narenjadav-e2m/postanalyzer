import { createRoot } from 'react-dom/client';
import './index.css';
import PostAnalyzer from './PostAnalyzer';

const root = document.getElementById('postanalyzer-root');
if (root) {
  createRoot(root).render(<PostAnalyzer />);
}
