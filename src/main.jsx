import { StrictMode } from "react";
import ReactDOM from 'react-dom/client';
import './index.css';
import PostAnalyzer from './PostAnalyzer';

const container = document.getElementById('postanalyzer-root');
if (container) {
  const root = ReactDOM.createRoot(container);
  root.render(
    <StrictMode>
      <PostAnalyzer />
    </StrictMode>
  );
}
