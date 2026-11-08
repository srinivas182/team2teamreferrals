import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import { viteSingleFile } from "vite-plugin-singlefile";
// Single-file build so the prototype can be published as one self-contained page.
export default defineConfig({ plugins: [react(), viteSingleFile()], build: { assetsInlineLimit: 100000000 } });
