FROM mcr.microsoft.com/playwright:v1.55.0-noble

WORKDIR /srv
COPY package.json package-lock.json ./
RUN npm ci --omit=dev --ignore-scripts && npm cache clean --force
COPY server.mjs runtime.mjs target-proxy.mjs ./

ENV NODE_ENV=production \
    PLAYWRIGHT_BROWSERS_PATH=/ms-playwright \
    PORT=3000
USER pwuser
EXPOSE 3000
HEALTHCHECK --interval=10s --timeout=5s --start-period=15s --retries=3 \
  CMD node -e "fetch('http://127.0.0.1:3000/health',{headers:{Authorization:'Bearer '+process.env.BROWSER_GATEWAY_TOKEN}}).then(r=>r.json()).then(v=>process.exit(v.status==='ok'&&v.sandbox===true?0:1)).catch(()=>process.exit(1))"
CMD ["node", "server.mjs"]
