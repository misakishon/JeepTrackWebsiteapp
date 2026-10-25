import test from 'node:test';
import assert from 'node:assert/strict';
import {setDatabaseData,DEFAULT_FILTERS,getData,breakdown,availableHours} from '../../resources/js/admin/data.js';

const filters={...DEFAULT_FILTERS,from:'2026-10-24',to:'2026-10-25'};
const trip={id:'trip-1',date:'2026-10-25',route:'route-1',driver:'driver-1',vehicle:'vehicle-1',status:'Completed',direction:null,departure:'2026-10-25T09:00:00Z',arrival:'2026-10-25T09:30:00Z',duration:30,departureLate:3,arrivalLate:null,scheduledDeparture:'2026-10-25T08:57:00Z',scheduledArrival:null,distance:null,gps:null};
const dataset={routes:[{id:'route-1',name:'Batac–Laoag',stops:['Batac Terminal','Laoag Terminal']}],drivers:[{id:'driver-1',name:'Test driver'}],vehicles:[{id:'vehicle-1',plate:'LOCAL-001',availability:{'2026-10-24':8,'2026-10-25':8}},{id:'vehicle-2',plate:'LOCAL-002',availability:{}}],trips:[trip],journeys:[],feedback:[],meta:{source:'Isolated test records',journeysAvailable:true}};

test('live missing schedules and GPS remain unavailable; inactive fleet vehicles remain in utilization denominator',()=>{
 setDatabaseData(dataset);const data=getData(filters),rows=breakdown(data,filters);
 assert.equal(data.metrics.onTime,100);assert.equal(data.metrics.delayRate,null);assert.equal(data.metrics.distance,null);
 assert.equal(rows.routeRows[0].scheduled,null);assert.equal(rows.vehicleRows.length,2);assert.equal(rows.vehicleRows[0].available,16);assert.equal(rows.vehicleRows[1].utilization,null);
 assert.equal(availableHours(dataset.vehicles[0],{...filters,from:'2026-10-23'}),null);
 assert.equal(getData({...filters,direction:'Inbound'}).trips.length,0);
});

test('journey date and active trip records drive passenger demand independently of completed trip dates',()=>{
 setDatabaseData({...dataset,trips:[{...trip,status:'In Progress',date:'2026-10-23'}],journeys:[{id:'journey-1',trip:'trip-1',date:'2026-10-25',route:'route-1',driver:'driver-1',vehicle:'vehicle-1',passenger:'passenger-1',boarding:'Batac Terminal',alighting:null,hour:23,dayOfWeek:0}]});
 const data=getData(filters);assert.equal(data.metrics.trips,0);assert.equal(data.metrics.journeys,1);assert.equal(data.metrics.unique,1);assert.equal(breakdown(data,filters).stopRows[0].alighting,0);
});
