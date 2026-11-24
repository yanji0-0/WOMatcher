const mockRecords = [
  { id:"r1", type:"Sequence", identifier:"6397261", date:"2026-11-18", description:"Replace damaged ELB feeder cable and restore lighting circuit for Office Cluster A.", source:"sequence-register-042.pdf", status:"Extracted" },
  { id:"r2", type:"ELB WO", identifier:"100000314", date:"2026-11-18", description:"Replace feeder cable to ELB; restore Office Cluster A lighting circuit.", source:"elb-work-orders-nov.pdf", status:"Extracted" },
  { id:"r3", type:"Sequence", identifier:"6397262", date:"2026-11-19", description:"Inspect and tighten busbar connections in Building 12 distribution panel.", source:"sequence-register-042.pdf", status:"Extracted" },
  { id:"r4", type:"ELB WO", identifier:"100000315", date:"2026-11-19", description:"Busbar connection inspection and tightening, Building 12 distribution panel.", source:"elb-work-orders-nov.pdf", status:"Extracted" },
  { id:"r5", type:"Sequence", identifier:"6397263", date:"2026-11-20", description:"Replace faulty 32A circuit breaker serving the South Workshop area.", source:"sequence-register-042.pdf", status:"Extracted" },
  { id:"r6", type:"ELB WO", identifier:"100000316", date:"2026-11-21", description:"32A breaker replacement for South Workshop, reference SW-32A-07.", source:"elb-work-orders-nov.pdf", status:"Extracted" },
  { id:"r7", type:"Sequence", identifier:"6397264", date:"2026-11-21", description:"No load test and thermal scan for incoming transformer panel.", source:"sequence-register-043.pdf", status:"Extracted" },
  { id:"r8", type:"ELB WO", identifier:"100000317", date:"2026-11-22", description:"Thermal imaging of transformer panel; incoming side inspection.", source:"elb-work-orders-nov.pdf", status:"Extracted" }
];

const mockMatches = [
  { id:"m1", sequence:"6397261", elb:"100000314", status:"Suggested", dateMatch:true, similarity:88, references:"ELB-A18, Cluster A", explanation:"Matching date and strong description overlap indicate the same electrical work location." },
  { id:"m2", sequence:"6397262", elb:"100000315", status:"Confirmed", dateMatch:true, similarity:91, references:"BDG12-ELB", explanation:"Confirmed from matching date, location reference, and nearly identical work scope." },
  { id:"m3", sequence:"6397263", elb:"100000316", status:"Conflict", dateMatch:false, similarity:72, references:"SW-32A-07", explanation:"The reference matches, but the record dates differ and require manual review." },
  { id:"m4", sequence:"6397264", elb:"100000317", status:"Suggested", dateMatch:false, similarity:76, references:"—", explanation:"Similar transformer scope, with a one-day date difference and no common reference number." },
  { id:"m5", sequence:"6397265", elb:"—", status:"Unmatched", dateMatch:false, similarity:0, references:"—", explanation:"No ELB WO record met the review threshold for this sequence record." }
];

const state = {
  files: { sequence:[], elb:[] },
  records:[], matches:[],
  recordPage:1, matchPage:1, pageSize:5,
  sessionCount:42
};

const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector) => [...document.querySelectorAll(selector)];
const iconFile = `<svg viewBox="0 0 24 24"><path d="M7 3h7l4 4v14H7zM14 3v5h5M10 13h5M10 17h5"/></svg>`;
const iconTrash = `<svg viewBox="0 0 24 24"><path d="M5 7h14M9 7V4h6v3M8 7l1 13h6l1-13M10 10v7M14 10v7"/></svg>`;

function formatSize(bytes){ if(!bytes) return "1.8 MB"; if(bytes<1024*1024) return `${(bytes/1024).toFixed(0)} KB`; return `${(bytes/1024/1024).toFixed(1)} MB`; }
function formatDate(value){ return new Date(`${value}T00:00:00`).toLocaleDateDateString ? new Date(`${value}T00:00:00`).toLocaleDateString("en-US",{month:"short",day:"numeric",year:"numeric"}) : value; }

function toast(title,message,type=""){
  const el=document.createElement("div"); el.className=`toast ${type}`; el.innerHTML=`<div>${iconFile}</div><div><strong>${title}</strong><span>${message}</span></div>`;
  $("#toastRegion").appendChild(el); setTimeout(()=>el.remove(),3800);
}

function switchView(view){
  $$(".view").forEach(el=>el.classList.remove("is-active")); $(`#${view}View`).classList.add("is-active");
  $$(".tab").forEach(el=>el.classList.toggle("is-active",el.dataset.view===view));
  window.scrollTo({top:0,behavior:"smooth"});
}

$$('[data-view]').forEach(btn=>btn.addEventListener("click",()=>switchView(btn.dataset.view)));
$$('[data-go]').forEach(btn=>btn.addEventListener("click",()=>switchView(btn.dataset.go)));

function setupDropZone(type){
  const drop=$(`#${type}Drop`), input=$(`#${type}Input`);
  $(`.browse-btn[data-target="${type}Input"]`).addEventListener("click",()=>input.click());
  input.addEventListener("change",()=>handleFiles(type,input.files));
  ["dragenter","dragover"].forEach(name=>drop.addEventListener(name,e=>{e.preventDefault();drop.classList.add("is-dragging") }));
  ["dragleave","drop"].forEach(name=>drop.addEventListener(name,e=>{e.preventDefault();drop.classList.remove("is-dragging") }));
  drop.addEventListener("drop",e=>handleFiles(type,e.dataTransfer.files));
}

function handleFiles(type,files){
  [...files].forEach(file=>{
    if(file.type && file.type!=="application/pdf" || !file.name.toLowerCase().endsWith(".pdf")){ toast("Invalid file","Only PDF files can be added.","error"); return; }
    if(state.files[type].some(f=>f.name===file.name)){ toast("Duplicate file",`${file.name} is already in this session.`,"error"); return; }
    state.files[type].push({name:file.name,size:file.size||1835000});
  });
  renderFiles();
  if(state.files[type].length){
    toast("PDF upload complete",`${state.files[type].length} file(s) simulated successfully`);
    extractRecords();
  }
}

function renderFiles(){
  ["sequence","elb"].forEach(type=>{
    const area=$(`#${type}Files`); area.innerHTML="";
    state.files[type].forEach((file,index)=>{
      const item=document.createElement("div"); item.className="file-item";
      item.innerHTML=`<div class="file-item-icon">${iconFile}</div><div class="file-info"><strong title="${file.name}">${file.name}</strong><span>${formatSize(file.size)} · Upload complete</span></div><button class="file-remove" aria-label="Remove ${file.name}" data-remove="${type}" data-index="${index}">${iconTrash}</button><div class="file-progress"><i></i></div>`;
      area.appendChild(item);
    });
  });
  $$("[data-remove]").forEach(btn=>btn.addEventListener("click",()=>removeFile(btn.dataset.remove,Number(btn.dataset.index))));
  $("#pdfMetric").textContent=state.files.sequence.length+state.files.elb.length;
}

function removeFile(type,index){
  const file=state.files[type][index];
  openConfirm("Delete PDF",`Remove ${file.name}? Its associated extracted records and matches will be affected.`,()=>{
    state.files[type].splice(index,1); renderFiles(); extractRecords(); toast("PDF removed",`${file.name} was removed from this demo session.`);
  },"Delete PDF");
}

function extractRecords(){
  const hasSequence=state.files.sequence.length>0, hasElb=state.files.elb.length>0;
  if(hasSequence&&hasElb){ state.records=mockRecords.map(r=>({...r})); }
  else if(hasSequence){ state.records=mockRecords.filter(r=>r.type==="Sequence").map(r=>({...r})); }
  else if(hasElb){ state.records=mockRecords.filter(r=>r.type==="ELB WO").map(r=>({...r})); }
  else { state.records=[]; }
  updateCounts(); renderRecords();
}

function updateCounts(){
  $("#recordCountTab").textContent=state.records.length;
  $("#recordCountLabel").textContent=`${state.records.length} records`;
  $("#recordMetric").textContent=state.records.length;
  $("#recordMetricDetail").textContent=state.records.length?`${state.files.sequence.length} Sequence · ${state.files.elb.length} ELB PDF`:"Waiting for PDF upload";
  $("#matchCountTab").textContent=state.matches.length;
  $("#potentialMetric").textContent=state.matches.filter(m=>m.status!=="Unmatched").length;
  $("#confirmedMetric").textContent=state.matches.filter(m=>m.status==="Confirmed").length;
  $("#resetBtn").disabled=!state.matches.length;
}

function renderRecords(){
  const search=$("#recordSearch").value.toLowerCase(), type=$("#recordTypeFilter").value, sort=$("#recordSort").value;
  let rows=state.records.filter(r=> (type==="all"||r.type===type) && (`${r.identifier} ${r.description} ${r.source}`).toLowerCase().includes(search));
  rows.sort((a,b)=>sort==="newest"?b.date.localeCompare(a.date):a.date.localeCompare(b.date));
  const body=$("#recordsBody"); body.innerHTML="";
  rows.forEach(r=>{
    const tr=document.createElement("tr"); tr.innerHTML=`<td><span class="type-pill ${r.type==="Sequence"?"sequence":"elb"}">${r.type}</span></td><td><span class="identifier">${r.identifier}</span></td><td>${formatDate(r.date)}</td><td class="description-cell"><span>${r.description}</span><br><button class="expand-description">Expand</button></td><td>${r.source}</td><td><span class="status-pill confirmed">${r.status}</span></td>`;
    body.appendChild(tr);
  });
  $$(".expand-description").forEach(btn=>btn.onclick=()=>{const cell=btn.closest(".description-cell");cell.classList.toggle("is-expanded");btn.textContent=cell.classList.contains("is-expanded")?"Collapse":"Expand"});
  $("#recordsEmpty").classList.toggle("is-visible",rows.length===0); $(".table-container",$("#recordsView")).style.display=rows.length?"block":"none";
  $("#recordPagination").innerHTML="";
}

function renderMatches(){
  const search=$("#matchSearch").value.toLowerCase(), status=$("#matchStatusFilter").value;
  let rows=state.matches.filter(m=>(status==="all"||m.status===status)&&`${m.sequence} ${m.elb}`.toLowerCase().includes(search));
  const body=$("#matchesBody"); body.innerHTML="";
  rows.forEach(m=>{
    const tr=document.createElement("tr"); tr.innerHTML=`<td><input type="checkbox" class="match-check" data-id="${m.id}" aria-label="Select match ${m.sequence}"></td><td><span class="identifier">${m.sequence}</span></td><td><span class="identifier ${m.elb==="—"?"":""}">${m.elb}</span></td><td><span class="status-pill ${m.status.toLowerCase()}">${m.status}</span></td><td><div class="row-actions">${m.status!=="Unmatched"?`<button class="action-link" data-review="${m.id}">Review</button>`:`<button class="action-link" data-manual-row="${m.sequence}">Match</button>`}</div></td>`;
    body.appendChild(tr);
  });
  $$("[data-review]").forEach(btn=>btn.onclick=()=>openReview(btn.dataset.review));
  $$("[data-manual-row]").forEach(btn=>btn.onclick=()=>openManual(btn.dataset.manualRow));
  $$(".match-check").forEach(ch=>ch.onchange=updateBulkButtons);
  $("#matchesEmpty").classList.toggle("is-visible",rows.length===0);
  $(".table-container",$("#matchesView")).style.display=rows.length?"block":"none";
  renderMatchSummary(); updateCounts(); updateBulkButtons(); $("#selectAllMatches").checked=false;
}

function renderMatchSummary(){
  const counts={Confirmed:0,Suggested:0,Unmatched:0,Conflict:0}; state.matches.forEach(m=>counts[m.status]=(counts[m.status]||0)+1);
  const colors={Confirmed:"#26704d",Suggested:"#9a6711",Unmatched:"#687480",Conflict:"#b13e3e"};
  $("#matchSummary").innerHTML=Object.entries(counts).map(([label,count])=>`<div class="summary-item"><i style="background:${colors[label]}"></i><div><span>${label}</span><strong>${count}</strong></div></div>`).join("");
}

function openReview(id){
  const m=state.matches.find(x=>x.id===id); if(!m)return;
  const seq=mockRecords.find(r=>r.identifier===m.sequence)||mockRecords[0];
  const elb=mockRecords.find(r=>r.identifier===m.elb)||{identifier:m.elb,date:"2026-11-21",description:"Potential ELB work order record requires source verification.",source:"elb-work-orders-nov.pdf"};
  $("#drawerBody").innerHTML=`<div class="comparison-grid"><article class="record-compare"><span class="record-kind">Sequence Record</span><span class="identifier">${seq.identifier}</span><div class="record-meta"><div>Date <strong>${formatDate(seq.date)}</strong></div><div>Original description<br><strong>${seq.description}</strong></div><div>Source PDF <strong>${seq.source}</strong></div></div></article><article class="record-compare"><span class="record-kind">ELB WO Record</span><span class="identifier">${elb.identifier}</span><div class="record-meta"><div>Date <strong>${formatDate(elb.date)}</strong></div><div>Original description<br><strong>${elb.description}</strong></div><div>Source PDF <strong>${elb.source}</strong></div></div></article></div><section class="match-analysis"><div class="analysis-top"><strong>Algorithmic match analysis</strong><span class="status-pill ${m.status.toLowerCase()}">${m.status}</span></div><div class="match-indicators"><div class="indicator ${m.dateMatch?"good":"warn"}"><span>Matching date</span><strong>${m.dateMatch?"Match":"Not matched"}</strong></div><div class="indicator ${m.similarity>=80?"good":"warn"}"><span>Description similarity</span><strong>${m.similarity}%</strong></div><div class="indicator"><span>Reference numbers</span><strong>${m.references}</strong></div></div><div class="algorithm-note"><strong>Suggested match explanation:</strong> ${m.explanation}<br><br>Similarity scores are algorithmic indicators, not guarantees of correctness.</div><div class="drawer-actions"><button class="btn btn-secondary" data-reject-review="${m.id}">Reject Match</button><button class="btn btn-primary" data-confirm-review="${m.id}">Confirm Match</button></div></section>`;
  $("#reviewDrawer").classList.add("is-open"); $("#reviewDrawer").setAttribute("aria-hidden","false");
  $(`[data-confirm-review="${m.id}"]`).onclick=()=>{m.status="Confirmed";closeDrawer();renderMatches();toast("Match confirmed",`${m.sequence} matched with ${m.elb}`)};
  $(`[data-reject-review="${m.id}"]`).onclick=()=>{m.status="Unmatched";m.elb="—";closeDrawer();renderMatches();toast("Match rejected","The pair was moved to unmatched.")};
}

function closeDrawer(){ $("#reviewDrawer").classList.remove("is-open"); $("#reviewDrawer").setAttribute("aria-hidden","true"); }
$$('[data-close-drawer]').forEach(el=>el.onclick=closeDrawer);

function openConfirm(title,message,onConfirm,label="Confirm"){
  $("#modalTitle").textContent=title; $("#modalMessage").textContent=message; $("#modalConfirmBtn").textContent=label;
  $("#confirmModal").classList.add("is-open"); $("#confirmModal").setAttribute("aria-hidden","false");
  $("#modalConfirmBtn").onclick=()=>{closeConfirm();onConfirm()};
}
function closeConfirm(){ $("#confirmModal").classList.remove("is-open"); $("#confirmModal").setAttribute("aria-hidden","true"); }
$$('[data-close-modal]').forEach(el=>el.onclick=closeConfirm);

$("#resetBtn").onclick=()=>openConfirm("Reset matching results","Matching results and confirmations will be cleared. Uploaded PDFs and extracted records will remain.",()=>{state.matches=[];renderMatches();switchView("matches");toast("Matching reset","Results cleared; source files and records were kept.")},"Reset Results");

$("#newSessionBtn").onclick=()=>openConfirm("Start new matching session","This clears uploaded PDFs, extracted records, and matching results in the current frontend demo.",()=>{
  state.files={sequence:[],elb:[]};state.records=[];state.matches=[];renderFiles();renderRecords();renderMatches();switchView("workspace");state.sessionCount++;$("#sessionName").textContent=`WOM-2026-${String(state.sessionCount).padStart(3,"0")}`;toast("New session started","The workspace is ready for source PDFs.");
},"Start New Session");

$("#rerunBtn").onclick=()=>{
  if(!state.records.length){toast("Matching unavailable","Upload at least one PDF to begin matching.","error");return}
  $("#loadingOverlay").classList.add("is-open"); $("#loadingOverlay").setAttribute("aria-hidden","false");
  setTimeout(()=>{state.matches=mockMatches.map(m=>({...m}));$("#loadingOverlay").classList.remove("is-open");$("#loadingOverlay").setAttribute("aria-hidden","true");renderMatches();switchView("matches");toast("Matching complete","5 simulated results generated for review.")},1400);
};

function updateBulkButtons(){const selected=$$(".match-check:checked").length; $("#confirmSelectedBtn").disabled=!selected;$("#rejectSelectedBtn").disabled=!selected; }
$("#selectAllMatches").onchange=e=>{$$(".match-check").forEach(ch=>ch.checked=e.target.checked);updateBulkButtons()};
$("#confirmSelectedBtn").onclick=()=>{const ids=$$(".match-check:checked").map(ch=>ch.dataset.id);state.matches.forEach(m=>{if(ids.includes(m.id)&&m.elb!=="—")m.status="Confirmed"});renderMatches();toast("Matches confirmed",`${ids.length} selected match(es) confirmed.`)};
$("#rejectSelectedBtn").onclick=()=>openConfirm("Reject selected matches","Reject the selected match(es) and move them to unmatched?",()=>{const ids=$$(".match-check:checked").map(ch=>ch.dataset.id);state.matches.forEach(m=>{if(ids.includes(m.id)){m.status="Unmatched";m.elb="—"}});renderMatches();toast("Matches rejected",`${ids.length} selected match(es) rejected.`)},"Reject Matches");

function openManual(sequence=""){$("#manualSequence").value=sequence;$("#manualElb").value="";$("#manualModal").classList.add("is-open")}
function closeManual(){ $("#manualModal").classList.remove("is-open") }
$$('[data-close-manual]').forEach(el=>el.onclick=closeManual);
$("#manualMatchBtn").onclick=()=>openManual();
$("#createManualBtn").onclick=()=>{const seq=$("#manualSequence").value.trim(),elb=$("#manualElb").value.trim();if(!/^\d{7}$/.test(seq)||!/^\d{9}$/.test(elb)){toast("Invalid identifiers","Enter 7 digits for Sequence and 9 digits for ELB WO.","error");return}const existing=state.matches.find(m=>m.sequence===seq);if(existing){existing.elb=elb;existing.status="Confirmed"}else{state.matches.push({id:`m${Date.now()}`,sequence:seq,elb,dateMatch:false,similarity:0,status:"Confirmed",references:"Manual",explanation:"Created manually by the user."})}closeManual();renderMatches();toast("Manual match created",`${seq} matched with ${elb}.`)};

$("#exportBtn").onclick=()=>{const count=state.matches.filter(m=>m.status==="Confirmed").length;$("#exportCount").textContent=count;$("#exportModal").classList.add("is-open")};
function closeExport(){ $("#exportModal").classList.remove("is-open") }
$$('[data-close-export]').forEach(el=>el.onclick=closeExport);
$("#downloadCsvBtn").onclick=()=>{const confirmed=state.matches.filter(m=>m.status==="Confirmed"&&m.elb!=="—");const csv=["7-digit Sequence No.,9-digit ELB WO",...confirmed.map(m=>`${m.sequence},${m.elb}`)].join("\n");const blob=new Blob([csv],{type:"text/csv;charset=utf-8"});const url=URL.createObjectURL(blob);const a=document.createElement("a");a.href=url;a.download="confirmed-work-order-matches.csv";a.click();URL.revokeObjectURL(url);closeExport();toast("Export ready","CSV downloaded with confirmed identifier pairs only.")};

$("#recordSearch").oninput=renderRecords; $("#recordTypeFilter").onchange=renderRecords; $("#recordSort").onchange=renderRecords;
$("#matchSearch").oninput=renderMatches; $("#matchStatusFilter").onchange=renderMatches;

setupDropZone("sequence");setupDropZone("elb");renderFiles();renderRecords();renderMatches();
