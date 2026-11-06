import { useState } from 'react';
import DatasetList from './Datasets/DatasetList';
import DatasetDetail from './Datasets/DatasetDetail';

export default function Datasets() {
  const [openId, setOpenId] = useState<number | null>(null);
  return openId ? <DatasetDetail id={openId} onBack={() => setOpenId(null)} /> : <DatasetList onOpen={setOpenId} />;
}
